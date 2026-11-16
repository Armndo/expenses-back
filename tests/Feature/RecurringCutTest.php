<?php

use App\Models\Category;
use App\Models\Expense;
use App\Models\ExchangeRate;
use App\Models\Setting;
use App\Models\Source;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Passport\Passport;

/*
 * "This and the following" of a recurring expense (phase 3.10a, stage B1): `PUT|DELETE /expenses/{rule}?scope=following&occurrence_date=`
 * ends the rule the day before that charge and starts a new one on it, in one transaction, without a migration and without touching what
 * the money does: a cut is two rules, and two rules are what stage A already adds up.
 */

/**
 * The charges of a source dated from `$from` to `$to` (`YYYY-MM`), each one `occurrence_date => amount`, from /data. A period is named by the
 * month it starts in, so a charge of the 10th of a card with cutoff 15 is in the previous month: one more month is read on each side.
 */
function cutCharges($test, Source $source, string $from, string $to): array {
  $found = [];

  for ($month = Carbon::parse("$from-01")->subMonth(); $month->lte(Carbon::parse("$to-01")->addMonth()); $month->addMonth()) {
    $data = $test->getJson("/data?date=" . $month->format("Y-m") . "-01")->assertOk()->json("expenses");
    $items = collect($data)->firstWhere("id", $source->id)["expenses"];

    foreach ($items as $item) {
      if ($item["recurs_every"] !== null && $item["occurrence_date"] >= "$from-01" && $item["occurrence_date"] <= "$to-31") {
        $found[$item["occurrence_date"]] = $item["amount"];
      }
    }
  }

  ksort($found);

  return $found;
}

/** What the rule of the tests is saved as before a cut: Netflix, monthly from 10 March 2026 on the card with cutoff 15. */
function cutRule(Source $source, array $attributes = []): Expense {
  return Expense::factory()->for($source)->recurring()->on("2026-03-10")->create(["amount" => 199, "description" => "Netflix", ...$attributes]);
}

beforeEach(function () {
  config(["expenses.balance_start" => "2026-01-01"]);
  Carbon::setTestNow("2026-11-17");

  $this->user = User::factory()->create();
  $this->card = Source::factory()->for($this->user)->cutoff(15)->create(["name" => "card 15"]);
  $this->late = Source::factory()->for($this->user)->cutoff(27)->create(["name" => "card 27"]);
  $this->dollars = Source::factory()->for($this->user)->create(["name" => "dollars", "currency" => "USD"]);
  $this->category = Category::factory()->for($this->user)->create();

  Passport::actingAs($this->user);
});

afterEach(fn () => Carbon::setTestNow());

describe("cutting a rule", function () {
  it("ends the rule the day before the charge and starts a new one on it, with the new amount", function () {
    $rule = cutRule($this->card);

    $response = $this->putJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-09-10", ["amount" => 249])->assertOk();

    expect(Expense::count())->toBe(2);
    expect($rule->fresh()->ends_on)->toBe("2026-09-09");

    $new = Expense::find($response->json("id"));
    expect($new->id)->not->toBe($rule->id);
    expect([substr($new->date, 0, 10), $new->amount, $new->recurs_every, $new->ends_on, $new->source_id, $new->description])
      ->toBe(["2026-09-10", 249.0, 1, null, $this->card->id, "Netflix"]);
    expect($response->json("period"))->toBe("2026-08"); // a card with cutoff 15 names a period by the month it starts in
  });

  it("keeps the past: the charges before it are the old rule's, at the old amount, and the ones after are the new one's", function () {
    $rule = cutRule($this->card);

    $this->putJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-09-10", ["amount" => 249])->assertOk();

    $charges = cutCharges($this, $this->card, "2026-03", "2027-02");
    expect($charges)->toBe([
      "2026-03-10" => 199, "2026-04-10" => 199, "2026-05-10" => 199, "2026-06-10" => 199, "2026-07-10" => 199, "2026-08-10" => 199,
      "2026-09-10" => 249, "2026-10-10" => 249, "2026-11-10" => 249, "2026-12-10" => 249, "2027-01-10" => 249, "2027-02-10" => 249,
    ]);
  });

  it("does not read the date of the request: the new rule starts on the charge that was cut", function () {
    $rule = cutRule($this->card);

    $response = $this->putJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-09-10", ["amount" => 249, "date" => "2026-03-10"])->assertOk();

    expect(substr(Expense::find($response->json("id"))->date, 0, 10))->toBe("2026-09-10");
  });

  it("edits the rule itself when the cut is its first charge, so no dead rule is left", function () {
    $rule = cutRule($this->card);

    $response = $this->putJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-03-10", ["amount" => 249, "date" => "2026-05-05"])->assertOk();

    expect(Expense::count())->toBe(1);
    expect($response->json("id"))->toBe($rule->id);
    expect([substr($rule->fresh()->date, 0, 10), $rule->fresh()->amount, $rule->fresh()->ends_on])->toBe(["2026-03-10", 249.0, null]);
  });

  it("keeps what the request does not send, and the new rule inherits the end of the old one", function () {
    $rule = cutRule($this->card, ["ends_on" => "2027-03-10", "category_id" => $this->category->id, "next" => true]);

    $response = $this->putJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-09-10", ["amount" => 249])->assertOk();
    $new = Expense::find($response->json("id"));

    expect([$new->ends_on, $new->category_id, $new->next])->toBe(["2027-03-10", $this->category->id, true]);
    expect($rule->fresh()->ends_on)->toBe("2026-09-09");
  });

  it("takes the end of the request when it sends one, null included", function () {
    $rule = cutRule($this->card, ["ends_on" => "2027-03-10"]);

    $new = Expense::find($this->putJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-09-10", ["ends_on" => "2027-06-10"])->json("id"));
    expect($new->ends_on)->toBe("2027-06-10");

    $other = cutRule($this->card, ["ends_on" => "2027-03-10"]);
    $forever = Expense::find($this->putJson("/expenses/{$other->id}?scope=following&occurrence_date=2026-09-10", ["ends_on" => null])->json("id"));
    expect($forever->ends_on)->toBeNull();
  });

  it("can change the category, how often it repeats and when it is billed", function () {
    $rule = cutRule($this->card);

    $new = Expense::find($this->putJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-09-10", [
      "category_id" => $this->category->id, "recurs_every" => 3, "next" => true, "description" => "Netflix, yearly now",
    ])->assertOk()->json("id"));

    expect([$new->category_id, $new->recurs_every, $new->next, $new->description])->toBe([$this->category->id, 3, true, "Netflix, yearly now"]);
    expect(array_keys(cutCharges($this, $this->card, "2026-08", "2027-06")))->toBe(["2026-08-10", "2026-09-10", "2026-12-10", "2027-03-10", "2027-06-10"]);
  });

  it("can move the rest to another source, with another cutoff and another currency", function () {
    $rule = cutRule($this->card);

    $late = Expense::find($this->putJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-09-10", ["source_id" => $this->late->id])->assertOk()->json("id"));
    expect($late->source_id)->toBe($this->late->id);
    expect(array_keys(cutCharges($this, $this->late, "2026-08", "2026-11")))->toBe(["2026-09-10", "2026-10-10", "2026-11-10"]);
    expect(array_keys(cutCharges($this, $this->card, "2026-08", "2026-11")))->toBe(["2026-08-10"]);

    $in = Expense::find($this->putJson("/expenses/{$late->id}?scope=following&occurrence_date=2026-11-10", ["source_id" => $this->dollars->id, "amount" => 10])->assertOk()->json("id"));
    expect([$in->source_id, $in->amount])->toBe([$this->dollars->id, 10.0]);
    expect(array_keys(cutCharges($this, $this->dollars, "2026-10", "2026-12")))->toBe(["2026-11-10", "2026-12-10"]);
  });

  it("can be cut again: chained cuts A, B and C leave no gap and no duplicate", function () {
    ExchangeRate::create(["user_id" => $this->user->id, "currency" => "USD", "rate" => 20]);
    $a = cutRule($this->card);

    $b = $this->putJson("/expenses/{$a->id}?scope=following&occurrence_date=2026-06-10", ["amount" => 249])->assertOk()->json("id");
    $c = $this->putJson("/expenses/$b?scope=following&occurrence_date=2026-10-10", ["amount" => 299])->assertOk()->json("id");

    expect(Expense::count())->toBe(3);
    expect([$a->fresh()->ends_on, Expense::find($b)->ends_on, Expense::find($c)->ends_on])->toBe(["2026-06-09", "2026-10-09", null]);

    $charges = cutCharges($this, $this->card, "2026-03", "2027-01");
    expect(array_keys($charges))->toBe(collect(range(0, 10))->map(fn($k) => Carbon::parse("2026-03-10")->addMonths($k)->toDateString())->all());
    expect(array_values($charges))->toBe([199, 199, 199, 249, 249, 249, 249, 299, 299, 299, 299]);
  });

  it("does not change the money of the months before the cut (March to July: August already holds the charge of the 10th of September)", function () {
    Setting::create(["user_id" => $this->user->id, "balance_start" => "2026-01-01", "budget_start" => "2026-01-01", "monthly_budget" => 10000]);
    $rule = cutRule($this->card);

    $before = collect(range(0, 4))->map(fn($k) => $this->getJson("/data?date=" . Carbon::parse("2026-03-01")->addMonths($k)->toDateString())->json("summary"))->all();

    $this->putJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-09-10", ["amount" => 249])->assertOk();

    $after = collect(range(0, 4))->map(fn($k) => $this->getJson("/data?date=" . Carbon::parse("2026-03-01")->addMonths($k)->toDateString())->json("summary"))->all();
    expect($after)->toEqual($before);
    expect($this->getJson("/data?date=2026-09-01")->json("summary.recurring"))->toBe(249);
  });
});

describe("when something fails half way", function () {
  it("leaves nothing behind: if the old rule cannot be ended the new one is not made either", function () {
    $rule = cutRule($this->card);
    Expense::updating(fn() => throw new Exception("the old rule could not be saved"));

    try {
      $this->putJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-09-10", ["amount" => 249])->assertStatus(400)->assertExactJson(["error"]);
    } finally {
      Expense::flushEventListeners();
    }

    expect(Expense::count())->toBe(1);
    expect([$rule->fresh()->ends_on, $rule->fresh()->amount])->toBe([null, 199.0]);
  });
});

describe("the day it falls on", function () {
  it("anchors the new rule to the 28th when the cut is on the 28th of February of a rule of the 31st", function () {
    $rule = cutRule($this->card, ["date" => "2026-01-31"]);

    $new = Expense::find($this->putJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-02-28", ["amount" => 249])->assertOk()->json("id"));

    expect(substr($new->date, 0, 10))->toBe("2026-02-28");
    expect($rule->fresh()->ends_on)->toBe("2026-02-27");
    expect(array_keys(cutCharges($this, $this->card, "2026-01", "2026-04")))->toBe(["2026-01-31", "2026-02-28", "2026-03-28", "2026-04-28"]);
  });

  it("keeps the 31st when the cut is on a charge of a month that has it", function () {
    $rule = cutRule($this->card, ["date" => "2026-01-31"]);

    $this->putJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-03-31", ["amount" => 249])->assertOk();

    expect(cutCharges($this, $this->card, "2026-01", "2026-05"))->toBe([
      "2026-01-31" => 199, "2026-02-28" => 199, "2026-03-31" => 249, "2026-04-30" => 249, "2026-05-31" => 249,
    ]);
  });

  it("knows the charge of a leap year that falls on the 28th of the next February", function () {
    $rule = cutRule($this->card, ["date" => "2024-02-29", "recurs_every" => 12]);

    $this->putJson("/expenses/{$rule->id}?scope=following&occurrence_date=2025-03-01", ["amount" => 249])->assertStatus(400);
    $this->putJson("/expenses/{$rule->id}?scope=following&occurrence_date=2025-02-28", ["amount" => 249])->assertOk();

    expect($rule->fresh()->ends_on)->toBe("2025-02-27");
  });
});

describe("what it refuses", function () {
  it("refuses a date that is not a charge of the rule, and changes nothing", function () {
    $rule = cutRule($this->card, ["ends_on" => "2026-12-10"]);

    foreach (["2026-09-11", "2026-02-10", "2027-01-10", "2026-09", "not a date", "2026-02-30", ""] as $date) {
      $this->putJson("/expenses/{$rule->id}?scope=following&occurrence_date=$date", ["amount" => 249])->assertStatus(400)->assertExactJson(["error"]);
    }

    $this->putJson("/expenses/{$rule->id}?scope=following", ["amount" => 249])->assertStatus(400);
    expect(Expense::count())->toBe(1);
    expect([$rule->fresh()->amount, $rule->fresh()->ends_on])->toBe([199.0, "2026-12-10"]);
  });

  it("refuses a second time with the same charge, because the old rule no longer has it", function () {
    $rule = cutRule($this->card);

    $this->putJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-09-10", ["amount" => 249])->assertOk();
    $this->putJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-09-10", ["amount" => 249])->assertStatus(400);

    expect(Expense::count())->toBe(2);
  });

  it("refuses a rest that is not valid, and leaves the rule as it was", function () {
    $rule = cutRule($this->card);
    $mine = $this->category;
    $theirs = Category::factory()->for(User::factory()->create())->create();
    $disabled = Source::factory()->for($this->user)->create(["name" => "disabled", "disabled" => true]);

    $bodies = [
      ["recurs_every" => null], ["recurs_every" => 0], ["recurs_every" => 61], ["recurs_every" => "often"],
      ["instalments" => 3], ["ends_on" => "2026-06-10"], ["ends_on" => "soon"], ["amount" => null],
      ["category_id" => $theirs->id], ["source_id" => Source::factory()->for(User::factory()->create())->create()->id], ["source_id" => $disabled->id],
    ];

    foreach ($bodies as $body) {
      $this->putJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-09-10", $body)->assertStatus(400);
    }

    expect(Expense::where("source_id", "!=", $this->card->id)->count())->toBe(0);
    expect([Expense::count(), $rule->fresh()->ends_on, $rule->fresh()->amount])->toBe([1, null, 199.0]);
    $this->putJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-09-10", ["category_id" => $mine->id])->assertOk();
  });

  it("does not cut the rule of someone else", function () {
    $theirs = cutRule(Source::factory()->for(User::factory()->create())->create());

    $this->putJson("/expenses/{$theirs->id}?scope=following&occurrence_date=2026-09-10", ["amount" => 249])->assertStatus(400);
    expect(Expense::count())->toBe(1);
  });
});

describe("the other scopes of an edit", function () {
  it("edits the whole rule without a scope or with scope=all, as before", function () {
    $rule = cutRule($this->card);

    $this->putJson("/expenses/{$rule->id}", ["amount" => 210])->assertOk();
    expect($rule->fresh()->amount)->toBe(210.0);

    $this->putJson("/expenses/{$rule->id}?scope=all", ["amount" => 220])->assertOk();
    expect($rule->fresh()->amount)->toBe(220.0);
    expect(Expense::count())->toBe(1);
  });

  it("refuses any other scope on a rule instead of editing all of it", function () {
    $rule = cutRule($this->card);

    foreach (["one", "this", "everything"] as $scope) {
      $this->putJson("/expenses/{$rule->id}?scope=$scope", ["amount" => 250])->assertStatus(400);
    }

    expect($rule->fresh()->amount)->toBe(199.0);
  });

  it("ignores the scope on anything that is not a rule", function () {
    $plain = Expense::factory()->for($this->card)->on("2026-09-10")->create(["amount" => 100]);
    $instalments = Expense::factory()->for($this->card)->instalments(6)->on("2026-09-10")->create(["amount" => 600]);

    foreach (["following", "one", "all", "whatever"] as $scope) {
      $this->putJson("/expenses/{$plain->id}?scope=$scope&occurrence_date=2026-09-10", ["amount" => 110])->assertOk();
      $this->putJson("/expenses/{$instalments->id}?scope=$scope&occurrence_date=2026-09-10", ["amount" => 660])->assertOk();
    }

    expect([$plain->fresh()->amount, $instalments->fresh()->amount, Expense::count()])->toBe([110.0, 660.0, 2]);
  });
});

describe("ending a rule from a charge", function () {
  it("ends the rule the day before the charge and keeps the past", function () {
    $rule = cutRule($this->card);

    $this->deleteJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-09-10")->assertOk();

    expect([Expense::count(), $rule->fresh()->ends_on])->toBe([1, "2026-09-09"]);
    expect(array_keys(cutCharges($this, $this->card, "2026-03", "2027-03")))->toBe([
      "2026-03-10", "2026-04-10", "2026-05-10", "2026-06-10", "2026-07-10", "2026-08-10",
    ]);
  });

  it("deletes the rule when the charge is the first one", function () {
    $rule = cutRule($this->card);

    $this->deleteJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-03-10")->assertOk();

    expect(Expense::count())->toBe(0);
  });

  it("refuses a date that is not a charge, a second time and a scope it does not know, and keeps the rule", function () {
    $rule = cutRule($this->card, ["ends_on" => "2026-12-10"]);

    $this->deleteJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-09-11")->assertStatus(400);
    $this->deleteJson("/expenses/{$rule->id}?scope=following&occurrence_date=2027-01-10")->assertStatus(400);
    $this->deleteJson("/expenses/{$rule->id}?scope=following")->assertStatus(400);
    $this->deleteJson("/expenses/{$rule->id}?scope=one&occurrence_date=2026-09-10")->assertStatus(400);
    expect($rule->fresh()->ends_on)->toBe("2026-12-10");

    $this->deleteJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-09-10")->assertOk();
    $this->deleteJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-09-10")->assertStatus(400);
    expect([Expense::count(), $rule->fresh()->ends_on])->toBe([1, "2026-09-09"]);
  });

  it("ignores the scope on a plain expense, which is deleted as always", function () {
    $plain = Expense::factory()->for($this->card)->on("2026-09-10")->create();

    $this->deleteJson("/expenses/{$plain->id}?scope=following&occurrence_date=2026-09-10")->assertOk();

    expect(Expense::count())->toBe(0);
  });

  it("does not end the rule of someone else", function () {
    $theirs = cutRule(Source::factory()->for(User::factory()->create())->create());

    $this->deleteJson("/expenses/{$theirs->id}?scope=following&occurrence_date=2026-09-10")->assertStatus(400);
    expect($theirs->fresh()->ends_on)->toBeNull();
  });
});

describe("the money of a cut", function () {
  /** The same sources, settings and a transfer in every world, plus the rule of Netflix as `$build` makes it. */
  function cutWorld(callable $build): array {
    $user = User::factory()->create();
    Setting::create(["user_id" => $user->id, "balance_start" => "2026-01-01", "budget_start" => "2026-01-01", "monthly_budget" => 10000]);
    ExchangeRate::create(["user_id" => $user->id, "currency" => "USD", "rate" => 20]);

    $sources = [
      "bank" => Source::factory()->for($user)->create(["name" => "bank"]),
      "card15" => Source::factory()->for($user)->cutoff(15)->create(["name" => "card 15"]),
      "card27" => Source::factory()->for($user)->cutoff(27)->create(["name" => "card 27"]),
      "dollars" => Source::factory()->for($user)->create(["name" => "dollars", "currency" => "USD"]),
    ];
    $category = Category::factory()->for($user)->create(["name" => "A"]);
    $sources["bank"]->incomes()->create(["date" => "2026-01-02", "amount" => 100000, "skip_budget" => true]);
    $sources["bank"]->outgoingTransfers()->create(["to_source_id" => $sources["card15"]->id, "amount" => 700, "date" => "2026-09-25"]);

    Passport::actingAs($user);
    $build($user, $sources, $category);

    return [$user, $sources];
  }

  /** What /data says of the money of a month: the summary (without the part that is a rule), the positions and the categories. */
  function cutMoney($test, User $user, string $month): array {
    Passport::actingAs($user);
    $data = $test->getJson("/data?date=$month-01")->assertOk()->json();

    return [
      "summary" => collect($data["summary"])->except("recurring")->all(),
      "balances" => collect($data["expenses"])->mapWithKeys(fn($s) => [$s["name"] => [$s["balance"], $s["previous_balance"]]])->all(),
      "categories" => collect($data["categories"])->mapWithKeys(fn($c) => [$c["name"] => [$c["expenses_count"], $c["expenses_sum_amount"]]])->all(),
    ];
  }

  it("gives the same money as the same charges written by hand, month by month", function () {
    // Netflix 199 from March, 249 from September on the card of cutoff 15, and from January on 12 dollars on the one in dollars.
    [$cut] = cutWorld(function ($user, $s, $category) {
      $rule = Expense::factory()->for($s["card15"])->recurring()->on("2026-03-10")->create(["amount" => 199, "category_id" => $category->id]);
      $this->putJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-09-10", ["amount" => 249])->assertOk();

      $second = Expense::factory()->for($s["card27"])->recurring(2)->on("2026-02-28")->create(["amount" => 80]);
      $this->putJson("/expenses/{$second->id}?scope=following&occurrence_date=2026-08-28", ["source_id" => $s["dollars"]->id, "amount" => 12, "next" => true])->assertOk();
    });

    [$byHand] = cutWorld(function ($user, $s, $category) {
      for ($k = 0; ; $k++) {
        $date = Carbon::parse("2026-03-10")->addMonthsNoOverflow($k);

        if ($date->gt("2028-06-30")) {
          break;
        }

        $s["card15"]->expenses()->create(["date" => $date->toDateString(), "amount" => $date->lt("2026-09-10") ? 199 : 249, "category_id" => $category->id]);
      }

      for ($k = 0; ; $k++) {
        $date = Carbon::parse("2026-02-28")->addMonthsNoOverflow($k * 2);

        if ($date->gt("2028-06-30")) {
          break;
        }

        $date->lt("2026-08-28")
          ? $s["card27"]->expenses()->create(["date" => $date->toDateString(), "amount" => 80])
          : $s["dollars"]->expenses()->create(["date" => $date->toDateString(), "amount" => 12, "next" => true]);
      }
    });

    for ($month = Carbon::parse("2026-01-01"); $month->lte("2027-12-01"); $month->addMonth()) {
      $key = $month->format("Y-m");
      expect(cutMoney($this, $cut, $key))->toEqual(cutMoney($this, $byHand, $key), "the month $key");
    }
  });

  it("gives the same money when a rule is cut as when it is ended and another one is made by hand", function () {
    [$cut, $sources] = cutWorld(function ($user, $s) {
      $rule = Expense::factory()->for($s["card15"])->recurring()->on("2026-03-10")->create(["amount" => 199]);
      $this->putJson("/expenses/{$rule->id}?scope=following&occurrence_date=2026-09-10", ["amount" => 249])->assertOk();
    });

    [$two] = cutWorld(function ($user, $s) {
      Expense::factory()->for($s["card15"])->recurring(1, "2026-09-09")->on("2026-03-10")->create(["amount" => 199]);
      Expense::factory()->for($s["card15"])->recurring()->on("2026-09-10")->create(["amount" => 249]);
    });

    for ($month = Carbon::parse("2026-01-01"); $month->lte("2028-01-01"); $month->addMonth()) {
      $key = $month->format("Y-m");
      expect(cutMoney($this, $cut, $key))->toEqual(cutMoney($this, $two, $key), "the month $key");
    }

    Passport::actingAs($cut);
    $mine = collect($this->getJson("/history")->json("months"))->keyBy("month");
    Passport::actingAs($two);
    $theirs = collect($this->getJson("/history")->json("months"))->keyBy("month");
    expect($mine->toArray())->toEqual($theirs->toArray());
  });
});
