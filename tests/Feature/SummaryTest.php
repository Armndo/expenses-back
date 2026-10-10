<?php

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

  // Incomes: the opening balance counts as an ordinary income of the start month.
  $bank->incomes()->create(["date" => "2026-09-01", "amount" => 1000, "description" => "Saldo inicial"]);
  $bank->incomes()->create(["date" => "2026-09-30", "amount" => 500]);
  $bank->incomes()->create(["date" => "2026-10-31", "amount" => 500]);
  $card->incomes()->create(["date" => "2026-10-10", "amount" => 25]); // cutoff 15: September's period

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

it("leaves the rest of /data as it was", function () {
  $json = $this->getJson("/data?date=2026-10-01")->assertOk()->json();

  expect(array_keys($json))->toBe(["expenses", "categories", "summary", "rates"])
    ->and(collect($json["expenses"])->pluck("name")->all())->toBe(["bank", "card"]);
});
