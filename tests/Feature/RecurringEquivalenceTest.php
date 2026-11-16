<?php

use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Passport\Passport;

/*
 * A recurring expense must give the same money as the same charges written by hand as plain expenses. Two users get the same
 * world: in one the recurring expenses are rules (one row each, the charges are worked out), in the other every charge is a row.
 * Everything that is money (the summary, the positions, the sums by category and the months of /history) has to match.
 */

/** The rules of the world: source key, first date, amount, repeats every N months, ends on, next, category key. */
function recurringRules(): array {
  return [
    "netflix" => ["card15", "2026-03-10", 199, 1, null, false, "a"],
    "rent on the 31st" => ["bank", "2026-01-31", 9000, 1, null, false, "b"],
    "yearly" => ["card0", "2026-02-15", 1299, 12, null, false, "a"],
    "gym every 3 months, ends on a charge" => ["card27", "2026-04-28", 500, 3, "2026-10-28", false, "b"],
    "billed next period, ends mid month" => ["card15", "2026-05-20", 300, 1, "2026-09-30", true, "a"],
    "dollars" => ["dollars", "2026-06-05", 10, 1, null, false, "a"],
    "refund every 2 months" => ["bank", "2026-07-01", -50, 2, null, false, null],
    "starts in the future" => ["card0", "2026-12-05", 80, 1, null, false, "b"],
  ];
}

/** A user with the same sources, settings and regular movements in both worlds. */
function recurringWorld(bool $withRules, bool $expanded): array {
  $user = User::factory()->create();
  Setting::create(["user_id" => $user->id, "balance_start" => "2026-01-01", "budget_start" => "2026-01-01", "monthly_budget" => 10000]);
  ExchangeRate::create(["user_id" => $user->id, "currency" => "USD", "rate" => 20]);

  $sources = [
    "bank" => $user->sources()->create(["name" => "bank"]),
    "dollars" => $user->sources()->create(["name" => "dollars", "currency" => "USD"]),
    "card15" => $user->sources()->create(["name" => "card 15", "cutoff" => 15]),
    "card0" => $user->sources()->create(["name" => "card 0", "cutoff" => 0]),
    "card27" => $user->sources()->create(["name" => "card 27", "cutoff" => 27]),
  ];
  $categories = ["a" => $user->categories()->create(["name" => "A"]), "b" => $user->categories()->create(["name" => "B"])];

  // What both worlds have: income, plain expenses, an instalment, a transfer that pays a card.
  $sources["bank"]->incomes()->create(["date" => "2026-01-02", "amount" => 100000, "skip_budget" => true]);
  $sources["bank"]->expenses()->create(["date" => "2026-02-03", "amount" => 400, "category_id" => $categories["a"]->id]);
  $sources["card15"]->expenses()->create(["date" => "2026-08-20", "amount" => 600, "instalments" => 6, "category_id" => $categories["b"]->id]);
  $sources["card0"]->expenses()->create(["date" => "2026-10-12", "amount" => 250]);
  $user->sources()->first()->outgoingTransfers()->create(["to_source_id" => $sources["card15"]->id, "amount" => 700, "date" => "2026-09-25"]);

  if ($withRules) {
    foreach (recurringRules() as [$source, $date, $amount, $every, $endsOn, $next, $category]) {
      if (!$expanded) {
        $sources[$source]->expenses()->create([
          "date" => $date, "amount" => $amount, "next" => $next, "recurs_every" => $every, "ends_on" => $endsOn,
          "category_id" => $category ? $categories[$category]->id : null,
        ]);

        continue;
      }

      // By hand: the same charges, one row each, from the date of the rule and every N months (counting from the date, not from the last
      // charge: January 31st and then February 28th and March 31st), far enough that the last month compared has all its charges (a cutoff or `next` moves a charge of 2028 to a period of 2027).
      for ($k = 0; ; $k++) {
        $charge = Carbon::parse($date)->addMonthsNoOverflow($k * $every);

        if ($charge->gt("2028-06-30") || ($endsOn !== null && $charge->gt($endsOn))) {
          break;
        }

        $sources[$source]->expenses()->create([
          "date" => $charge->toDateString(), "amount" => $amount, "next" => $next,
          "category_id" => $category ? $categories[$category]->id : null,
        ]);
      }
    }
  }

  return [$user, $sources];
}

function recurringData(User $user, string $month): array {
  Passport::actingAs($user);

  return test()->getJson("/data?date=$month-01")->assertOk()->json();
}

/** What /data says about the money of a month, without ids or the lists of rows (those have different rows in each world). */
function recurringMoney(array $data): array {
  $sources = collect($data["expenses"])->mapWithKeys(fn($source) => [$source["name"] => [
    "balance" => $source["balance"],
    "previous_balance" => $source["previous_balance"],
    // What the source bills this month, from its rows: the regular ones and the monthly share of the instalments.
    "billed" => round(
      collect($source["expenses"])->sum("amount") + collect($source["instalments"])->sum(fn($e) => round($e["amount"] / $e["instalments"], 2)),
      2
    ),
  ]])->all();

  $categories = collect($data["categories"])->mapWithKeys(fn($c) => [$c["name"] => [$c["expenses_count"], $c["expenses_sum_amount"]]])->all();

  // `recurring` breaks `spent` down by what is a rule, so it is the one number that differs by hand (it is zero there): everything else is the same.
  return ["summary" => collect($data["summary"])->except("recurring")->all(), "sources" => $sources, "categories" => $categories];
}

beforeEach(function () {
  config(["expenses.balance_start" => "2026-01-01"]);
  Carbon::setTestNow("2026-11-17");
});

afterEach(fn () => Carbon::setTestNow());

it("gives the same money as the same charges written by hand, month by month", function () {
  [$rules] = recurringWorld(true, false);
  [$byHand] = recurringWorld(true, true);

  for ($month = Carbon::parse("2026-01-01"); $month->lte("2027-12-01"); $month->addMonth()) {
    $key = $month->format("Y-m");

    expect(recurringMoney(recurringData($rules, $key)))->toEqual(recurringMoney(recurringData($byHand, $key)), "the month $key");
  }
});

it("gives the same months in /history as the charges written by hand, in the months both have", function () {
  [$rules] = recurringWorld(true, false);
  [$byHand] = recurringWorld(true, true);

  Passport::actingAs($rules);
  $mine = collect($this->getJson("/history")->assertOk()->json("months"))->keyBy("month");
  Passport::actingAs($byHand);
  $theirs = collect($this->getJson("/history")->assertOk()->json("months"))->keyBy("month");

  expect($mine->count())->toBeGreaterThan(10);

  foreach ($mine as $month => $row) {
    // The budget of the months to come adds the recent spending pace to what is registered, and by hand the pace
    // already holds the charges of the past months: only what is real or registered is compared there.
    $keys = ["spent", "income", "cash", "debt", "pending_instalments", "total_money", "last_month_owed", "last_month_paid"];
    $past = Carbon::parse($month)->lte("2026-11-30");

    expect(collect($row)->only($past ? [...$keys, "budget", "budget_carry", "budget_left"] : $keys)->all())
      ->toEqual(collect($theirs[$month])->only($past ? [...$keys, "budget", "budget_carry", "budget_left"] : $keys)->all(), "the month $month");
  }
});

it("does not count the recurring expenses twice in the spending pace of the budget", function () {
  [$plain] = recurringWorld(false, false);
  [$rules] = recurringWorld(true, false);

  Passport::actingAs($plain);
  $without = $this->getJson("/history")->assertOk()->json("budget_pace");
  Passport::actingAs($rules);
  $with = $this->getJson("/history")->assertOk()->json("budget_pace");

  // The pace is what is spent on top of the instalments and of the charges that repeat: adding rules does not change it.
  expect($with)->toEqual($without);
});
