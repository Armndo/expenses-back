<?php

use App\Models\Expense;
use App\Models\Income;
use App\Models\Source;
use App\Models\Transfer;
use App\Models\User;
use Laravel\Passport\Passport;

function balanceData($test, string $month): array {
  return $test->getJson("/data?date=$month")->assertOk()->json();
}

function balanceTotals($test, string $month): array {
  return collect(balanceData($test, $month)["summary"])->only(["cash", "debt", "total_money"])->map(fn ($v) => $v === null ? null : round($v, 2))->all();
}

beforeEach(function () {
  config(["expenses.balance_start" => "2026-09-01"]);

  $this->user = User::factory()->create();
  $this->bank = Source::factory()->for($this->user)->create(["name" => "bank"]);
  $this->bank2 = Source::factory()->for($this->user)->create(["name" => "bank 2"]);
  $this->card = Source::factory()->for($this->user)->cutoff(15)->create(["name" => "card"]);

  Income::factory()->for($this->bank)->on("2026-09-01")->create(["amount" => 1000]);   // opening balance
  Expense::factory()->for($this->bank)->on("2026-09-05")->create(["amount" => 50]);
  Expense::factory()->for($this->card)->on("2026-09-20")->create(["amount" => 300]);   // September's period (Sep 16 - Oct 15)

  Passport::actingAs($this->user);
});

it("splits the balance into cash in the accounts and debt in the cards", function () {
  // bank 1000 - 50, card owes 300.
  expect(balanceTotals($this, "2026-09-01"))->toBe(["cash" => 950.0, "debt" => 300.0, "total_money" => 650.0]);
});

it("keeps the balance when a card is paid, moving cash into debt", function () {
  Transfer::factory()->between($this->bank, $this->card)->on("2026-09-20")->create(["amount" => 300]);

  expect(balanceTotals($this, "2026-09-01"))->toBe(["cash" => 650.0, "debt" => 0.0, "total_money" => 650.0]);
});

it("leaves the card at exactly zero after paying what the bank bills for instalments", function () {
  // 100.01 / 3 = 33.3367: the bank bills 33.34 a month, so 100.02 for three such purchases.
  foreach (range(1, 3) as $i) {
    Expense::factory()->for($this->card)->on("2026-09-20")->instalments(3)->create(["amount" => 100.01]);
  }
  Transfer::factory()->between($this->bank, $this->card)->on("2026-09-25")->create(["amount" => 300 + 100.02]);

  $card = collect(balanceData($this, "2026-09-01")["expenses"])->firstWhere("name", "card");

  expect($card["balance"])->toEqual(0)
    ->and(balanceTotals($this, "2026-09-01")["debt"])->toEqual(0);
});

it("rounds an exact half cent the same in the balance and in the category sums", function () {
  // 14405.58 / 12 = 1200.465 exactly: halves round away from zero, to 1200.47, everywhere.
  $category = App\Models\Category::factory()->create();
  Expense::factory()->for($this->card)->on("2026-09-20")->instalments(12)->create(["amount" => 14405.58, "category_id" => $category->id]);

  $data = balanceData($this, "2026-09-01");
  $card = collect($data["expenses"])->firstWhere("name", "card");

  expect($card["balance"])->toEqual(-(300 + 1200.47))
    ->and(collect($data["categories"])->firstWhere("id", $category->id)["expenses_sum_amount"])->toEqual(1200.47);
});

it("rounds the share of a refund away from zero too", function () {
  expect(App\Services\Summary::share(14405.58, 12))->toEqual(1200.47)
    ->and(App\Services\Summary::share(-14405.58, 12))->toEqual(-1200.47)
    ->and(App\Services\Summary::share(100.01, 3))->toEqual(33.34)
    ->and(App\Services\Summary::share(3000, 6))->toEqual(500);
});

it("handles a partial payment and an overpayment", function () {
  $payment = Transfer::factory()->between($this->bank, $this->card)->on("2026-09-20")->create(["amount" => 100]);

  expect(balanceTotals($this, "2026-09-01"))->toBe(["cash" => 850.0, "debt" => 200.0, "total_money" => 650.0]);

  $payment->update(["amount" => 400]);

  expect(balanceTotals($this, "2026-09-01"))->toBe(["cash" => 550.0, "debt" => -100.0, "total_money" => 650.0]);
});

it("counts a payment in the month it falls in", function () {
  Transfer::factory()->between($this->bank, $this->card)->on("2026-10-20")->create(["amount" => 300]);

  expect(balanceTotals($this, "2026-09-01"))->toBe(["cash" => 950.0, "debt" => 300.0, "total_money" => 650.0])
    ->and(balanceTotals($this, "2026-10-01"))->toBe(["cash" => 650.0, "debt" => 0.0, "total_money" => 650.0]);
});

it("never moves the balance of any month with a payment dated between two cutoffs", function () {
  // The card's September period ends on Oct 15 but the bank's ends on Sep 30: a payment on Oct 10 must
  // not show as paid in September for one side only.
  Transfer::factory()->between($this->bank, $this->card)->on("2026-10-10")->create(["amount" => 300]);

  expect(balanceTotals($this, "2026-09-01"))->toBe(["cash" => 950.0, "debt" => 300.0, "total_money" => 650.0])
    ->and(balanceTotals($this, "2026-10-01"))->toBe(["cash" => 650.0, "debt" => 0.0, "total_money" => 650.0]);
});

it("always has total_money equal to cash minus debt", function () {
  Income::factory()->for($this->bank)->on("2026-10-08")->create(["amount" => 500]);
  Expense::factory()->for($this->card)->on("2026-10-20")->instalments(3)->create(["amount" => 900]);
  Transfer::factory()->between($this->bank, $this->card)->on("2026-10-25")->create(["amount" => 123.45]);
  Transfer::factory()->between($this->bank, $this->bank2)->on("2026-11-02")->create(["amount" => 80, "received_amount" => 75]);
  Transfer::factory()->between($this->bank2, $this->card)->on("2026-11-20")->create(["amount" => 30]);

  foreach (["2026-09-01", "2026-10-01", "2026-11-01", "2026-12-01", "2027-01-01"] as $month) {
    $totals = balanceTotals($this, $month);

    expect($totals["total_money"])->toEqualWithDelta($totals["cash"] - $totals["debt"], 0.011, $month);
  }
});

it("only loses what a conversion fee takes", function () {
  Transfer::factory()->between($this->bank, $this->bank2)->on("2026-09-10")->create(["amount" => 100, "received_amount" => 90]);

  // bank 1000 - 50 - 100, bank 2 +90, card owes 300.
  expect(balanceTotals($this, "2026-09-01"))->toBe(["cash" => 940.0, "debt" => 300.0, "total_money" => 640.0]);
});

it("shows each source's own position in /data, null before the start month", function () {
  Transfer::factory()->between($this->bank, $this->card)->on("2026-09-20")->create(["amount" => 100]);

  $balances = fn ($month) => collect(balanceData($this, $month)["expenses"])->pluck("balance", "name")->map(fn ($v) => $v === null ? null : round($v, 2))->all();

  expect($balances("2026-09-01"))->toBe(["bank" => 850.0, "bank 2" => 0.0, "card" => -200.0])
    ->and($balances("2026-08-01"))->toBe(["bank" => null, "bank 2" => null, "card" => null]);
});

it("ignores transfers before the balance start and other users' transfers", function () {
  Transfer::factory()->between($this->bank, $this->card)->on("2026-08-15")->create(["amount" => 500]);
  Transfer::factory()->between(Source::factory()->create(), Source::factory()->create())->on("2026-09-10")->create(["amount" => 999]);

  expect(balanceTotals($this, "2026-09-01"))->toBe(["cash" => 950.0, "debt" => 300.0, "total_money" => 650.0]);
});

it("does not count transfers as spent or as income", function () {
  Transfer::factory()->between($this->bank, $this->card)->on("2026-09-20")->create(["amount" => 300]);

  $summary = balanceData($this, "2026-09-01")["summary"];

  expect($summary["spent"])->toEqual(350)->and($summary["income"])->toEqual(1000);
});

it("has no cash, debt or balance before the start month", function () {
  expect(balanceTotals($this, "2026-08-01"))->toBe(["cash" => null, "debt" => null, "total_money" => null]);
});

it("gives each source the position it had at the close of the previous month", function () {
  Transfer::factory()->between($this->bank, $this->card)->on("2026-10-03")->create(["amount" => 100]);

  $card = fn (string $month) => collect(balanceData($this, $month)["expenses"])->firstWhere("name", "card");

  // October's previous balance is September's own balance (the card owed 300); October's transfer is not in it.
  expect($card("2026-10-01")["previous_balance"])->toEqual($card("2026-09-01")["balance"])
    ->and($card("2026-10-01")["previous_balance"])->toEqual(-300)
    ->and($card("2026-10-01")["balance"])->toEqual(-200);
});

it("has no previous balance in the start month or before it", function () {
  $card = fn (string $month) => collect(balanceData($this, $month)["expenses"])->firstWhere("name", "card");

  expect($card("2026-09-01")["previous_balance"])->toBeNull()
    ->and($card("2026-08-01")["previous_balance"])->toBeNull();
});
