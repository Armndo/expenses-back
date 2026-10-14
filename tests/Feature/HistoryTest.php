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

  $bank->incomes()->create(["date" => "2026-09-01", "amount" => 1000, "skip_budget" => true]);
  $bank->incomes()->create(["date" => "2026-10-20", "amount" => 500, "skip_budget" => true]);

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

it("has at least the current month, even when everything starts in the future", function () {
  $this->user->sources()->each(fn ($source) => $source->expenses()->delete());
  config(["expenses.balance_start" => "2027-01-01"]);

  $months = historyOf($this);

  expect(array_column($months, "month"))->toBe(["2026-11-01"])
    ->and($months[0]["total_money"])->toBeNull();
});

it("goes on after the current month while instalments are still to bill, as a projection", function () {
  // 1200 in 4 instalments of 300 from October: October, November, December and January.
  $this->user->sources()->where("name", "bank")->first()->expenses()->create(["date" => "2026-10-20", "amount" => 1200, "instalments" => 4]);

  $months = collect(historyOf($this))->keyBy("month");

  expect(array_keys($months->all()))->toBe(["2026-07-01", "2026-08-01", "2026-09-01", "2026-10-01", "2026-11-01", "2026-12-01", "2027-01-01"])
    // Nothing else bills: December and January spend only the instalment, and what is still to come shrinks.
    ->and($months["2026-12-01"])->toMatchArray(["spent" => 300.0, "income" => 0.0, "pending_instalments" => 300.0])
    ->and($months["2027-01-01"])->toMatchArray(["spent" => 300.0, "pending_instalments" => 0.0]);
});

it("keeps at most the last 120 months", function () {
  $this->user->sources()->first()->expenses()->create(["date" => "2010-01-05", "amount" => 1]);

  $months = historyOf($this);

  expect($months)->toHaveCount(120)
    ->and($months[119]["month"])->toBe("2026-11-01");
});

it("projects the budget at the recent spending pace on top of what is registered", function () {
  $user = User::factory()->create();
  $bank = $user->sources()->create(["name" => "bank"]);
  Setting::create(["user_id" => $user->id, "balance_start" => "2026-08-01", "monthly_budget" => 1000]);
  Passport::actingAs($user);

  $bank->expenses()->create(["date" => "2026-08-05", "amount" => 300]);
  $bank->expenses()->create(["date" => "2026-09-05", "amount" => 500]);
  $bank->expenses()->create(["date" => "2026-10-05", "amount" => 400]);
  $bank->expenses()->create(["date" => "2026-10-10", "amount" => 600, "instalments" => 3]); // 200 in October, November and December

  $response = $this->getJson("/history")->assertOk();
  $months = collect($response->json("months"))->keyBy("month");

  // The pace is what is spent besides the instalments over the last 3 months over: (300 + 500 + 400) / 3.
  expect($response->json("budget_pace"))->toEqual(400)
    // The current month (November) is real: 1000 + 1600 carried - 200 of the instalment.
    ->and($months["2026-11-01"])->toMatchArray(["budget_carry" => 1600.0, "budget_left" => 2400.0])
    // December is projected: what November left, 1000 - (200 of the instalment + the pace of 400).
    ->and($months["2026-12-01"])->toMatchArray(["budget" => 1000.0, "budget_carry" => 2400.0, "budget_left" => 2800.0]);

  // /data of a future month keeps what is registered: it does not assume a pace.
  expect($this->getJson("/data?date=2026-12-01")->json("summary.budget_left"))->toEqual(3200);
});

it("does not assume any pace when there is no month over to average", function () {
  $user = User::factory()->create();
  $bank = $user->sources()->create(["name" => "bank"]);
  Setting::create(["user_id" => $user->id, "balance_start" => "2026-11-01", "monthly_budget" => 1000]);
  Passport::actingAs($user);

  $bank->expenses()->create(["date" => "2026-11-05", "amount" => 600, "instalments" => 3]); // 200 in November, December and January

  $response = $this->getJson("/history")->assertOk();
  $months = collect($response->json("months"))->keyBy("month");

  expect($response->json("budget_pace"))->toEqual(0)
    ->and($months["2026-12-01"]["budget_left"])->toEqual(1000 + 800 - 200)
    ->and($months["2027-01-01"]["budget_left"])->toEqual(1000 + 1600 - 200);
});

it("has no pace without a budget", function () {
  $response = $this->getJson("/history")->assertOk();

  expect($response->json("budget_pace"))->toBeNull();
});
