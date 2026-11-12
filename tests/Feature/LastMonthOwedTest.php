<?php

use App\Models\Expense;
use App\Models\ExchangeRate;
use App\Models\Income;
use App\Models\Source;
use App\Models\Transfer;
use App\Models\User;
use Laravel\Passport\Passport;

function owedOf($test, string $month): mixed {
  return $test->getJson("/data?date=$month")->assertOk()->json("summary.last_month_owed");
}

beforeEach(function () {
  config(["expenses.balance_start" => "2026-09-01"]);

  $this->user = User::factory()->create();
  $this->bank = Source::factory()->for($this->user)->create(["name" => "bank"]);
  $this->card = Source::factory()->for($this->user)->cutoff(15)->create(["name" => "card"]);
  $this->card2 = Source::factory()->for($this->user)->cutoff(0)->create(["name" => "card 2"]);

  Income::factory()->for($this->bank)->on("2026-09-01")->create(["amount" => 10000]); // opening balance
  Expense::factory()->for($this->card)->on("2026-09-20")->create(["amount" => 800]);  // September's period (Sep 16 to Oct 15)
  Expense::factory()->for($this->card2)->on("2026-09-10")->create(["amount" => 200]);

  Passport::actingAs($this->user);
});

it("owes nothing in the balance start month, which has no previous month", function () {
  expect(owedOf($this, "2026-09-01"))->toBe(0);
});

it("adds what every card owed at the close of the previous month", function () {
  expect(owedOf($this, "2026-10-01"))->toBe(1000);
});

it("subtracts the payments of the month, card by card", function () {
  Transfer::factory()->between($this->bank, $this->card)->on("2026-10-05")->create(["amount" => 300]);

  expect(owedOf($this, "2026-10-01"))->toBe(700); // card 500 + card 2 200

  Transfer::factory()->between($this->bank, $this->card2)->on("2026-10-06")->create(["amount" => 200]);

  expect(owedOf($this, "2026-10-01"))->toBe(500); // card 2 is paid
});

it("never goes below zero when a payment is bigger than the debt", function () {
  Transfer::factory()->between($this->bank, $this->card2)->on("2026-10-06")->create(["amount" => 900]); // 200 owed, 900 paid
  Transfer::factory()->between($this->bank, $this->card)->on("2026-10-06")->create(["amount" => 300]);

  expect(owedOf($this, "2026-10-01"))->toBe(500); // card 500, and card 2 counts 0, not -700
});

it("is 0 when everything is paid", function () {
  Transfer::factory()->between($this->bank, $this->card)->on("2026-10-05")->create(["amount" => 800]);
  Transfer::factory()->between($this->bank, $this->card2)->on("2026-10-05")->create(["amount" => 200]);

  expect(owedOf($this, "2026-10-01"))->toBe(0);
});

it("converts a card in another currency with its rate and is null without one", function () {
  $dollars = Source::factory()->for($this->user)->cutoff(0)->create(["name" => "dollar card", "currency" => "USD"]);
  Expense::factory()->for($dollars)->on("2026-09-10")->create(["amount" => 10]); // 10 USD

  expect(owedOf($this, "2026-10-01"))->toBeNull(); // a currency with no rate: like debt

  ExchangeRate::create(["user_id" => $this->user->id, "currency" => "USD", "rate" => 20]);

  expect(owedOf($this, "2026-10-01"))->toBe(1200); // 1000 + 10 USD * 20
});

it("is null before the balance start and with no cards a plain 0", function () {
  expect(owedOf($this, "2026-08-01"))->toBeNull();

  $other = User::factory()->create();
  Source::factory()->for($other)->create(["name" => "only a bank"]);
  Passport::actingAs($other);

  expect(owedOf($this, "2026-10-01"))->toBe(0);
});

it("is the sum of what each card of /data says with its previous balance and its transfers", function () {
  Transfer::factory()->between($this->bank, $this->card)->on("2026-10-05")->create(["amount" => 300]);
  Expense::factory()->for($this->card)->on("2026-10-20")->create(["amount" => 90]); // this month's charge is not last month's debt

  $data = $this->getJson("/data?date=2026-10-01")->assertOk()->json();
  $expected = collect($data["expenses"])->where("kind", "card")->sum(function ($source) {
    $moved = collect($source["transfers"])->sum("signed_amount");

    return max(0, -($source["previous_balance"] + $moved));
  });

  expect((float) $data["summary"]["last_month_owed"])->toBe(round((float) $expected, 2))->and($expected)->toBeGreaterThan(0);
});

// ---- what the paid cards owed: the green chip

function paidOf($test, string $month): mixed {
  return $test->getJson("/data?date=$month")->assertOk()->json("summary.last_month_paid");
}

it("adds what the cards that are paid owed at the close of the previous month", function () {
  expect(paidOf($this, "2026-10-01"))->toBe(0); // nobody has paid yet

  Transfer::factory()->between($this->bank, $this->card2)->on("2026-10-06")->create(["amount" => 200]); // card 2 owed 200 and is paid

  expect(paidOf($this, "2026-10-01"))->toBe(200)->and(owedOf($this, "2026-10-01"))->toBe(800); // card 800 still owed

  Transfer::factory()->between($this->bank, $this->card)->on("2026-10-06")->create(["amount" => 800]);

  expect(paidOf($this, "2026-10-01"))->toBe(1000)->and(owedOf($this, "2026-10-01"))->toBe(0);
});

it("says what it owed, not what it paid, when a payment is bigger than the debt", function () {
  Transfer::factory()->between($this->bank, $this->card2)->on("2026-10-06")->create(["amount" => 900]);

  expect(paidOf($this, "2026-10-01"))->toBe(200);
});

it("has nothing paid in the balance start month, with no debt or before the start", function () {
  expect(paidOf($this, "2026-09-01"))->toBe(0)->and(paidOf($this, "2026-08-01"))->toBeNull();

  $other = User::factory()->create();
  Source::factory()->for($other)->cutoff(5)->create(["name" => "a card with no debt"]);
  Passport::actingAs($other);

  expect(paidOf($this, "2026-10-01"))->toBe(0);
});

it("is the sum of what each paid card says in its green chip", function () {
  Transfer::factory()->between($this->bank, $this->card2)->on("2026-10-06")->create(["amount" => 200]);
  Transfer::factory()->between($this->bank, $this->card)->on("2026-10-06")->create(["amount" => 1000]);

  $data = $this->getJson("/data?date=2026-10-01")->assertOk()->json();
  $expected = collect($data["expenses"])->where("kind", "card")->sum(function ($source) {
    $left = max(0, -($source["previous_balance"] + collect($source["transfers"])->sum("signed_amount")));

    return $left <= 0.005 && $source["previous_balance"] < -0.005 ? -$source["previous_balance"] : 0;
  });

  expect((float) $data["summary"]["last_month_paid"])->toBe(round((float) $expected, 2))->and($expected)->toBeGreaterThan(0);
});
