<?php

use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Passport\Passport;

function historyOf($test): array {
  return $test->getJson("/history")->assertOk()->json("months");
}

beforeEach(function () {
  config(["expenses.balance_start" => "2026-09-01"]);
  Carbon::setTestNow("2026-11-15");

  $this->user = User::factory()->create();
  $bank = $this->user->sources()->create(["name" => "bank"]);
  $card = $this->user->sources()->create(["name" => "card", "cutoff" => 15]);

  $bank->incomes()->create(["date" => "2026-09-01", "amount" => 1000]);
  $bank->incomes()->create(["date" => "2026-10-20", "amount" => 500]);

  $bank->expenses()->create(["date" => "2026-07-10", "amount" => 80]); // before the balance start
  $bank->expenses()->create(["date" => "2026-08-15", "amount" => 999]);
  $bank->expenses()->create(["date" => "2026-09-05", "amount" => 50]);
  $bank->expenses()->create(["date" => "2026-09-10", "amount" => 300, "instalments" => 3]);
  $card->expenses()->create(["date" => "2026-10-20", "amount" => 70]);

  // Someone else's money must not leak in, nor move the first month.
  $theirs = User::factory()->create()->sources()->create(["name" => "theirs"]);
  $theirs->expenses()->create(["date" => "2026-01-05", "amount" => 5000]);

  Passport::actingAs($this->user);
});

afterEach(fn () => Carbon::setTestNow());

it("runs from the month of the first expense to the current one", function () {
  $months = historyOf($this);

  expect(array_column($months, "month"))->toBe(["2026-07-01", "2026-08-01", "2026-09-01", "2026-10-01", "2026-11-01"]);
});

it("has the spent of every month but the rest only from the balance start", function () {
  $months = collect(historyOf($this))->keyBy("month");

  // July and August have expenses (80 and 999) but the balance is not counted yet.
  expect($months["2026-07-01"])->toMatchArray(["spent" => 80.0, "cash" => null, "debt" => null, "pending_instalments" => null, "total_money" => null])
    ->and($months["2026-08-01"])->toMatchArray(["spent" => 999.0, "total_money" => null])
    ->and($months["2026-09-01"]["total_money"])->not->toBeNull();
});

it("gives every month the same summary as /data", function () {
  foreach (historyOf($this) as $month) {
    $summary = $this->getJson("/data?date={$month['month']}")->assertOk()->json("summary");

    expect($month)->toMatchArray($summary);
  }
});

it("has no budget until the budget start and counts from there", function () {
  Setting::create(["user_id" => $this->user->id, "monthly_budget" => 400, "budget_start" => "2026-10-01"]);

  $months = collect(historyOf($this))->keyBy("month");

  expect($months["2026-09-01"])->toMatchArray(["budget" => null, "budget_carry" => null, "budget_left" => null])
    ->and($months["2026-10-01"])->toMatchArray(["budget" => 400.0, "budget_carry" => 0.0, "budget_left" => 230.0])   // 100 (instalment) + 70 spent
    ->and($months["2026-11-01"]["budget_carry"])->toEqual(230);
});

it("starts at the balance start when there is nothing earlier", function () {
  $this->user->sources()->each(fn ($source) => $source->expenses()->delete());

  expect(array_column(historyOf($this), "month"))->toBe(["2026-09-01", "2026-10-01", "2026-11-01"]);
});

it("is empty when everything starts in the future", function () {
  $this->user->sources()->each(fn ($source) => $source->expenses()->delete());
  config(["expenses.balance_start" => "2027-01-01"]);

  expect(historyOf($this))->toBe([]);
});

it("keeps at most the last 120 months", function () {
  $this->user->sources()->first()->expenses()->create(["date" => "2010-01-05", "amount" => 1]);

  $months = historyOf($this);

  expect($months)->toHaveCount(120)
    ->and($months[119]["month"])->toBe("2026-11-01");
});
