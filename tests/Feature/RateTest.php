<?php

use App\Models\ExchangeRate;
use App\Models\User;
use Laravel\Passport\Passport;

beforeEach(function () {
  $this->user = User::factory()->create();
  $this->other = User::factory()->create();

  Passport::actingAs($this->user);
});

it("sets the rate of a currency", function () {
  $this->putJson("/rates/USD", ["rate" => 17.85])->assertSuccessful()->assertJsonPath("currency", "USD")->assertJsonPath("rate", 17.85);

  expect($this->user->exchangeRates()->pluck("rate", "currency")->all())->toBe(["USD" => 17.85]);
});

it("replaces the rate instead of adding another", function () {
  $this->putJson("/rates/USD", ["rate" => 17.85])->assertSuccessful();
  $this->putJson("/rates/USD", ["rate" => "18.1"])->assertSuccessful();

  expect($this->user->exchangeRates()->count())->toBe(1)
    ->and($this->user->exchangeRates()->first()->rate)->toBe(18.1);
});

it("takes the currency in any case", function () {
  $this->putJson("/rates/usd", ["rate" => 17])->assertSuccessful();

  expect($this->user->exchangeRates()->first()->currency)->toBe("USD");
});

it("keeps the rates of each user apart", function () {
  ExchangeRate::create(["user_id" => $this->other->id, "currency" => "USD", "rate" => 20]);

  $this->putJson("/rates/USD", ["rate" => 17])->assertSuccessful();

  expect($this->other->exchangeRates()->first()->rate)->toBe(20.0)
    ->and($this->user->exchangeRates()->first()->rate)->toBe(17.0);
});

it("refuses a rate that is not valid", function (string $currency, mixed $rate) {
  $this->putJson("/rates/$currency", ["rate" => $rate])->assertStatus(400);

  expect($this->user->exchangeRates()->count())->toBe(0);
})->with([
  "the base currency" => ["MXN", 1],
  "an unknown currency" => ["EUR", 20],
  "zero" => ["USD", 0],
  "negative" => ["USD", -1],
  "text" => ["USD", "abc"],
  "none" => ["USD", null],
]);
