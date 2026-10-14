<?php

use App\Models\Setting;
use App\Models\User;
use Laravel\Passport\Passport;

function summaryOf($test, string $month): array {
  return $test->getJson("/data?date=$month")->assertOk()->json("summary");
}

beforeEach(function () {
  config(["expenses.balance_start" => "2026-09-01"]);

  $this->user = User::factory()->create();
  $bank = $this->user->sources()->create(["name" => "bank"]);
  $card = $this->user->sources()->create(["name" => "card", "cutoff" => 15]);

  // Incomes: the opening balance counts as an ordinary income of the start month. None of them is a refund,
  // so they skip the budget (the ones that do add to it have their own tests).
  $bank->incomes()->create(["date" => "2026-09-01", "amount" => 1000, "description" => "Saldo inicial", "skip_budget" => true]);
  $bank->incomes()->create(["date" => "2026-09-30", "amount" => 500, "skip_budget" => true]);
  $bank->incomes()->create(["date" => "2026-10-31", "amount" => 500, "skip_budget" => true]);
  $card->incomes()->create(["date" => "2026-10-10", "amount" => 25, "skip_budget" => true]); // cutoff 15: September's period

  // Before the start: shown as previous_spent of September, never subtracted from the balance.
  $bank->expenses()->create(["date" => "2026-08-15", "amount" => 999]);

  $bank->expenses()->create(["date" => "2026-09-05", "amount" => 50]);
  $bank->expenses()->create(["date" => "2026-09-10", "amount" => 300, "instalments" => 3]); // 100 in Sep, Oct and Nov
  $bank->expenses()->create(["date" => "2026-09-30", "amount" => 40, "next" => true]); // billed in October
  $card->expenses()->create(["date" => "2026-09-20", "amount" => 100]);
  $card->expenses()->create(["date" => "2026-10-15", "amount" => 70]); // last day of September's period
  $card->expenses()->create(["date" => "2026-10-16", "amount" => 30]); // first day of October's

  // Someone else's money must not leak in.
  $theirs = User::factory()->create()->sources()->create(["name" => "theirs"]);
  $theirs->incomes()->create(["date" => "2026-09-01", "amount" => 7777]);
  $theirs->expenses()->create(["date" => "2026-09-01", "amount" => 5000]);

  Passport::actingAs($this->user);
});

it("sums the month's spent and income", function () {
  // September: 50 + 100 (instalment) + 100 + 70; income 1000 + 500 + 25.
  expect(summaryOf($this, "2026-09-01"))->toMatchArray(["spent" => 320.0, "income" => 1525.0])
    // October: 100 (instalment) + 40 (next) + 30; income 500.
    ->and(summaryOf($this, "2026-10-01"))->toMatchArray(["spent" => 170.0, "income" => 500.0])
    ->and(summaryOf($this, "2026-11-01"))->toMatchArray(["spent" => 100.0, "income" => 0.0]);
});

it("runs the balance from the start month, carrying it over", function () {
  // Each month: the previous balance + its income - its spent - what its instalments will still bill.
  expect(summaryOf($this, "2026-09-01")["total_money"])->toEqual(1005)         // 1525 - 320 - 200
    ->and(summaryOf($this, "2026-10-01")["total_money"])->toEqual(1435)        // 1535 - 100
    ->and(summaryOf($this, "2026-11-01")["total_money"])->toEqual(1435)        // 1535 + 0 - 100
    ->and(summaryOf($this, "2026-12-01")["total_money"])->toEqual(1435);       // nothing moves
});

it("subtracts the instalments still to be billed, whole from their first period", function () {
  // The 300 in 3 instalments of September: 200 left after September, 100 after October.
  expect(summaryOf($this, "2026-09-01")["pending_instalments"])->toEqual(200)
    ->and(summaryOf($this, "2026-10-01")["pending_instalments"])->toEqual(100)
    ->and(summaryOf($this, "2026-11-01")["pending_instalments"])->toEqual(0)
    ->and(summaryOf($this, "2026-08-01")["pending_instalments"])->toBeNull();
});

it("does not count what was spent before the start month", function () {
  // August's 999 is left out: the opening balance is already net of it.
  expect(summaryOf($this, "2026-09-01")["total_money"])->toEqual(1005);
});

it("still starts in September 2026 when the config is stale", function () {
  config(["expenses.balance_start" => null]);

  expect(summaryOf($this, "2026-08-01")["total_money"])->toBeNull()
    ->and(summaryOf($this, "2026-09-01")["total_money"])->toEqual(1005);
});

it("has no balance before the start month", function () {
  expect(summaryOf($this, "2026-08-01")["total_money"])->toBeNull()
    ->and(summaryOf($this, "2026-08-01")["spent"])->toEqual(999);
});

it("moves the start month with the config", function () {
  config(["expenses.balance_start" => "2026-10-01"]);

  expect(summaryOf($this, "2026-09-01")["total_money"])->toBeNull()
    ->and(summaryOf($this, "2026-10-01")["total_money"])->toEqual(230)        // 500 - 170 - 100 (the November instalment of the September purchase)
    ->and(summaryOf($this, "2026-11-01")["total_money"])->toEqual(230);        // 230 + 0 - 100 + 100
});

it("takes the start month from the user's setting before the config", function () {
  Setting::create(["user_id" => $this->user->id, "balance_start" => "2026-10-01"]);

  expect(summaryOf($this, "2026-09-01")["total_money"])->toBeNull()
    ->and(summaryOf($this, "2026-10-01")["total_money"])->toEqual(230);
});

it("falls back to the config when the setting has no start month", function () {
  Setting::create(["user_id" => $this->user->id, "monthly_budget" => 1000]);

  expect(summaryOf($this, "2026-09-01")["total_money"])->toEqual(1005);
});

it("has no budget until the user sets one", function () {
  expect(summaryOf($this, "2026-09-01"))->toMatchArray(["budget" => null, "budget_carry" => null, "budget_left" => null]);
});

it("says what is left of the monthly budget and when it was exceeded", function () {
  Setting::create(["user_id" => $this->user->id, "monthly_budget" => 400]);

  // Spent 320 in September, 170 in October, 100 in November, nothing in December.
  expect(summaryOf($this, "2026-09-01"))->toMatchArray(["budget" => 400.0, "budget_carry" => 0.0, "budget_left" => 80.0])
    ->and(summaryOf($this, "2026-12-01")["budget_left"])->toEqual(1010);

  Setting::where("user_id", $this->user->id)->update(["monthly_budget" => 320]);
  expect(summaryOf($this, "2026-09-01")["budget_left"])->toEqual(0);

  Setting::where("user_id", $this->user->id)->update(["monthly_budget" => 300]);
  expect(summaryOf($this, "2026-09-01")["budget_left"])->toEqual(-20);
});

it("carries what each month left over, or owes, into the next one", function () {
  Setting::create(["user_id" => $this->user->id, "monthly_budget" => 400]);

  // September leaves 80, October 400 + 80 - 170 = 310 and November 400 + 310 - 100 = 610.
  expect(summaryOf($this, "2026-10-01"))->toMatchArray(["budget_carry" => 80.0, "budget_left" => 310.0])
    ->and(summaryOf($this, "2026-11-01"))->toMatchArray(["budget_carry" => 310.0, "budget_left" => 610.0])
    ->and(summaryOf($this, "2026-12-01"))->toMatchArray(["budget_carry" => 610.0, "budget_left" => 1010.0]);
});

it("carries the debt of a month that went over the budget", function () {
  Setting::create(["user_id" => $this->user->id, "monthly_budget" => 300]);

  // September goes over by 20, so October starts with 300 - 20 and spends 170; November owes nothing more.
  expect(summaryOf($this, "2026-09-01"))->toMatchArray(["budget_carry" => 0.0, "budget_left" => -20.0])
    ->and(summaryOf($this, "2026-10-01"))->toMatchArray(["budget_carry" => -20.0, "budget_left" => 110.0]);
});

it("counts the budget from its own start month", function () {
  Setting::create(["user_id" => $this->user->id, "monthly_budget" => 400, "budget_start" => "2026-10-01"]);

  expect(summaryOf($this, "2026-09-01"))->toMatchArray(["budget" => null, "budget_carry" => null, "budget_left" => null])
    ->and(summaryOf($this, "2026-10-01"))->toMatchArray(["budget" => 400.0, "budget_carry" => 0.0, "budget_left" => 230.0])
    ->and(summaryOf($this, "2026-11-01"))->toMatchArray(["budget_carry" => 230.0, "budget_left" => 530.0]);
});

it("starts the budget with the balance when it has no start month of its own", function () {
  Setting::create(["user_id" => $this->user->id, "monthly_budget" => 400, "budget_start" => null]);

  expect(summaryOf($this, "2026-08-01")["budget"])->toBeNull()
    ->and(summaryOf($this, "2026-09-01")["budget_left"])->toEqual(80);
});

it("has no budget before the balance start, like the balance", function () {
  Setting::create(["user_id" => $this->user->id, "monthly_budget" => 1000]);

  expect(summaryOf($this, "2026-08-01"))->toMatchArray(["total_money" => null, "budget" => null, "budget_carry" => null, "budget_left" => null])
    ->and(summaryOf($this, "2026-09-01"))->toMatchArray(["budget" => 1000.0, "budget_left" => 680.0]);
});

it("leaves the budget left empty while a currency has no rate", function () {
  Setting::create(["user_id" => $this->user->id, "monthly_budget" => 400]);
  $this->user->sources()->create(["name" => "dollars", "currency" => "USD"]);

  expect(summaryOf($this, "2026-09-01"))->toMatchArray(["budget" => 400.0, "budget_carry" => null, "budget_left" => null, "missing_rates" => ["USD"]]);
});

it("leaves the rest of /data as it was", function () {
  $json = $this->getJson("/data?date=2026-10-01")->assertOk()->json();

  expect(array_keys($json))->toBe(["expenses", "categories", "summary", "rates"])
    ->and(collect($json["expenses"])->pluck("name")->all())->toBe(["bank", "card"]);
});

it("gives back to the budget an income that is not flagged, without touching spent", function () {
  Setting::create(["user_id" => $this->user->id, "monthly_budget" => 400]);
  $this->user->sources()->where("name", "bank")->first()->incomes()->create(["date" => "2026-09-12", "amount" => 100, "description" => "refund"]);

  // September spent 320, so 400 - 320 + 100 left; spent is the same and the income shows in its own total.
  expect(summaryOf($this, "2026-09-01"))->toMatchArray(["spent" => 320.0, "income" => 1625.0, "budget_left" => 180.0]);
});

it("ignores the incomes flagged to skip the budget", function () {
  Setting::create(["user_id" => $this->user->id, "monthly_budget" => 400]);
  $this->user->sources()->where("name", "bank")->first()->incomes()->create(["date" => "2026-09-12", "amount" => 100, "description" => "salary", "skip_budget" => true]);

  expect(summaryOf($this, "2026-09-01"))->toMatchArray(["income" => 1625.0, "budget_left" => 80.0]);
});

it("carries an income that gave back to the budget into the next months", function () {
  Setting::create(["user_id" => $this->user->id, "monthly_budget" => 400]);
  $this->user->sources()->where("name", "bank")->first()->incomes()->create(["date" => "2026-09-12", "amount" => 100]);

  // September leaves 180 instead of 80, so October is 400 + 180 - 170 and November 400 + 410 - 100.
  expect(summaryOf($this, "2026-10-01"))->toMatchArray(["budget_carry" => 180.0, "budget_left" => 410.0])
    ->and(summaryOf($this, "2026-11-01"))->toMatchArray(["budget_carry" => 410.0, "budget_left" => 710.0]);
});

it("bills a budget income by the cutoff of its source, like the rest", function () {
  Setting::create(["user_id" => $this->user->id, "monthly_budget" => 400]);
  $card = $this->user->sources()->where("name", "card")->first();
  $card->incomes()->create(["date" => "2026-10-15", "amount" => 60]); // last day of September's period
  $card->incomes()->create(["date" => "2026-10-16", "amount" => 7]);  // first day of October's

  // September: 400 - 320 + 60 = 140. October: 400 + 140 - 170 + 7 = 377.
  expect(summaryOf($this, "2026-09-01")["budget_left"])->toEqual(140)
    ->and(summaryOf($this, "2026-10-01")["budget_left"])->toEqual(377);
});

it("does not count the incomes of other users in the budget", function () {
  Setting::create(["user_id" => $this->user->id, "monthly_budget" => 400]);
  $other = User::factory()->create()->sources()->create(["name" => "theirs 2"]);
  $other->incomes()->create(["date" => "2026-09-12", "amount" => 900]);

  expect(summaryOf($this, "2026-09-01")["budget_left"])->toEqual(80);
});
