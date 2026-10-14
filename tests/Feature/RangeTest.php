<?php

use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Passport\Passport;

function rangeOf($test): array {
  return $test->getJson("/data?date=2026-11-01")->assertOk()->json("range");
}

beforeEach(function () {
  config(["expenses.balance_start" => "2026-09-01"]);
  Carbon::setTestNow("2026-11-15");

  $this->user = User::factory()->create();
  $this->bank = $this->user->sources()->create(["name" => "bank"]);
  $this->card = $this->user->sources()->create(["name" => "card", "cutoff" => 15]);

  Passport::actingAs($this->user);
});

afterEach(fn () => Carbon::setTestNow());

it("is the current month for both ends when there is nothing yet", function () {
  config(["expenses.balance_start" => "2026-11-01"]);

  expect(rangeOf($this))->toBe(["first" => "2026-11-01", "last" => "2026-11-01"]);
});

it("starts at the month of the oldest expense, or at the balance start if that is earlier", function () {
  $this->bank->expenses()->create(["date" => "2026-07-10", "amount" => 80]);
  $this->bank->expenses()->create(["date" => "2026-10-02", "amount" => 80]);

  expect(rangeOf($this)["first"])->toBe("2026-07-01");

  $this->bank->expenses()->delete();
  $this->bank->expenses()->create(["date" => "2026-10-02", "amount" => 80]);

  expect(rangeOf($this)["first"])->toBe("2026-09-01");
});

it("ends at the current month when nothing bills later", function () {
  $this->bank->expenses()->create(["date" => "2026-10-02", "amount" => 80]);

  expect(rangeOf($this)["last"])->toBe("2026-11-01");
});

it("ends at the month of the last instalment", function () {
  // 6 instalments from December: December to May.
  $this->bank->expenses()->create(["date" => "2026-12-03", "amount" => 600, "instalments" => 6]);

  expect(rangeOf($this)["last"])->toBe("2027-05-01");
});

it("counts the next flag and the cutoff of the source", function () {
  // Billed next period: a purchase of 2026-12-20 on the bank goes to January.
  $this->bank->expenses()->create(["date" => "2026-12-20", "amount" => 50, "next" => true]);
  expect(rangeOf($this)["last"])->toBe("2027-01-01");

  // Cutoff 15: 2027-02-10 is still the period of January; 2027-02-16 is February's.
  $this->card->expenses()->create(["date" => "2027-02-10", "amount" => 50]);
  expect(rangeOf($this)["last"])->toBe("2027-01-01");

  $this->card->expenses()->create(["date" => "2027-02-16", "amount" => 50]);
  expect(rangeOf($this)["last"])->toBe("2027-02-01");
});

it("does not count the expenses of other users", function () {
  $theirs = User::factory()->create()->sources()->create(["name" => "theirs"]);
  $theirs->expenses()->create(["date" => "2024-01-05", "amount" => 5000]);
  $theirs->expenses()->create(["date" => "2028-01-05", "amount" => 5000, "instalments" => 12]);

  expect(rangeOf($this))->toBe(["first" => "2026-09-01", "last" => "2026-11-01"]);
});

it("starts at the budget start when there is a budget and it is earlier", function () {
  config(["expenses.balance_start" => "2026-10-01"]);
  Setting::create(["user_id" => $this->user->id, "monthly_budget" => 100, "balance_start" => "2026-10-01", "budget_start" => "2026-08-01"]);

  expect(rangeOf($this)["first"])->toBe("2026-08-01");
});

it("is the same in every month of /data", function () {
  $this->bank->expenses()->create(["date" => "2026-12-03", "amount" => 600, "instalments" => 6]);

  $other = $this->getJson("/data?date=2026-03-01")->assertOk()->json("range");

  expect($other)->toBe(rangeOf($this));
});
