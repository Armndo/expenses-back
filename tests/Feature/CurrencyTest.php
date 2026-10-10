<?php

use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Source;
use App\Models\Transfer;
use App\Models\User;
use Laravel\Passport\Passport;

function dataOf($test, string $month = "2026-10-01"): array {
  return $test->getJson("/data?date=$month")->assertOk()->json();
}

beforeEach(function () {
  config(["expenses.balance_start" => "2026-09-01"]);

  $this->user = User::factory()->create();
  $this->bank = Source::factory()->for($this->user)->create(["name" => "bank"]);
  $this->card = Source::factory()->for($this->user)->cutoff(15)->create(["name" => "card"]);
  $this->dollar = Source::factory()->for($this->user)->currency("USD")->create(["name" => "dollar app"]);

  $this->bank->incomes()->create(["date" => "2026-09-01", "amount" => 1000]);
  $this->dollar->incomes()->create(["date" => "2026-09-02", "amount" => 100]);

  Passport::actingAs($this->user);
});

function rate($test, float $rate): void {
  ExchangeRate::create(["user_id" => $test->user->id, "currency" => "USD", "rate" => $rate]);
}

it("values a source in dollars with the exchange rate", function () {
  rate($this, 17.5);

  $data = dataOf($this);

  // 1000 pesos + 100 USD * 17.5; the source itself keeps its own currency.
  expect($data["summary"])->toMatchArray(["cash" => 2750.0, "debt" => 0.0, "total_money" => 2750.0, "missing_rates" => []])
    ->and(collect($data["expenses"])->firstWhere("name", "dollar app"))->toMatchArray(["currency" => "USD", "balance" => 100.0])
    ->and($data["rates"])->toBe(["USD" => 17.5]);
});

it("converts what is spent and earned in a month", function () {
  rate($this, 17.5);
  $this->dollar->expenses()->create(["date" => "2026-10-03", "amount" => 10]);
  $this->dollar->expenses()->create(["date" => "2026-10-04", "amount" => 30, "instalments" => 3]);
  $this->bank->expenses()->create(["date" => "2026-10-05", "amount" => 100]);
  $this->dollar->incomes()->create(["date" => "2026-10-06", "amount" => 2]);

  // (10 + 30 / 3) USD * 17.5 + 100; income 2 USD * 17.5.
  expect(dataOf($this)["summary"])->toMatchArray(["spent" => 450.0, "income" => 35.0]);
});

it("converts the category sums with the rate of the source", function () {
  rate($this, 17.5);
  $category = Category::factory()->create();
  $this->dollar->expenses()->create(["date" => "2026-10-03", "amount" => 10, "category_id" => $category->id]);
  $this->dollar->expenses()->create(["date" => "2026-10-04", "amount" => 30, "instalments" => 3, "category_id" => $category->id]);
  $this->bank->expenses()->create(["date" => "2026-10-05", "amount" => 100, "category_id" => $category->id]);

  $sum = collect(dataOf($this)["categories"])->firstWhere("id", $category->id)["expenses_sum_amount"];

  expect($sum)->toEqual(450);
});

it("moves the balance only by the difference when dollars are converted", function () {
  rate($this, 17.85);
  $before = dataOf($this)["summary"]["total_money"];

  // 100 USD (worth 1785) become 1780 pesos: the 5 pesos lost are the only change.
  Transfer::factory()->between($this->dollar, $this->bank)->on("2026-10-04")->create(["amount" => 100, "received_amount" => 1780]);

  $data = dataOf($this);

  expect($before)->toEqual(2785)
    ->and($data["summary"]["total_money"])->toEqual(2780)
    ->and(collect($data["expenses"])->firstWhere("name", "dollar app")["balance"])->toEqual(0)
    ->and(collect($data["expenses"])->firstWhere("name", "bank")["balance"])->toEqual(2780);
});

it("shows each end of a conversion in its own currency", function () {
  rate($this, 17.85);
  Transfer::factory()->between($this->dollar, $this->bank)->on("2026-10-04")->create(["amount" => 100, "received_amount" => 1780]);

  $data = dataOf($this);
  $out = collect($data["expenses"])->firstWhere("name", "dollar app")["transfers"][0];
  $in = collect($data["expenses"])->firstWhere("name", "bank")["transfers"][0];

  expect([$out["direction"], $out["signed_amount"]])->toEqual(["out", -100])
    ->and([$in["direction"], $in["signed_amount"]])->toEqual(["in", 1780]);
});

it("pays a card from the dollars through pesos without changing the balance beyond the rate", function () {
  rate($this, 17);
  $this->card->expenses()->create(["date" => "2026-10-01", "amount" => 500]);
  // The card is paid from the bank with pesos that came from the dollars at the same rate.
  Transfer::factory()->between($this->dollar, $this->bank)->on("2026-10-02")->create(["amount" => 50, "received_amount" => 850]);
  Transfer::factory()->between($this->bank, $this->card)->on("2026-10-03")->create(["amount" => 500]);

  $summary = dataOf($this)["summary"];

  // bank 1000 + 850 - 500, dollars 50 USD * 17 = 850, card 0.
  expect($summary)->toMatchArray(["cash" => 2200.0, "debt" => 0.0, "total_money" => 2200.0]);
});

it("leaves the balance empty and lists the currency when a rate is missing", function () {
  $this->bank->expenses()->create(["date" => "2026-10-05", "amount" => 100]);
  $this->dollar->expenses()->create(["date" => "2026-10-05", "amount" => 10]);

  $summary = dataOf($this)["summary"];

  // The dollar source adds nothing to spent while it has no rate.
  expect($summary)->toMatchArray(["cash" => null, "debt" => null, "total_money" => null, "missing_rates" => ["USD"], "spent" => 100.0]);
});

it("does not ask for a rate when no source uses the currency", function () {
  $this->dollar->update(["currency" => "MXN"]);

  expect(dataOf($this)["summary"])->toMatchArray(["cash" => 1100.0, "total_money" => 1100.0, "missing_rates" => []])
    ->and($this->getJson("/data?date=2026-10-01")->getContent())->toContain('"rates":{}');
});

it("keeps the rates of other users out", function () {
  ExchangeRate::create(["user_id" => User::factory()->create()->id, "currency" => "USD", "rate" => 99]);

  expect(dataOf($this)["summary"]["missing_rates"])->toBe(["USD"])
    ->and(dataOf($this)["rates"])->toBe([]);
});

it("gives the same numbers as before when everything is in pesos", function () {
  $this->dollar->update(["currency" => "MXN"]);
  $this->bank->expenses()->create(["date" => "2026-10-05", "amount" => 100]);
  Transfer::factory()->between($this->bank, $this->card)->on("2026-10-06")->create(["amount" => 40]);

  // bank 1000 - 100 - 40, dollar source 100 (now pesos); the card holds the 40 it received.
  expect(dataOf($this)["summary"])->toMatchArray(["spent" => 100.0, "cash" => 960.0, "debt" => -40.0, "total_money" => 1000.0]);
});
