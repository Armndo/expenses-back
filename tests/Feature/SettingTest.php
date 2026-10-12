<?php

use App\Models\Setting;
use App\Models\User;
use Laravel\Passport\Passport;

beforeEach(function () {
  config(["expenses.balance_start" => "2026-09-01"]);

  $this->user = User::factory()->create();
  $this->other = User::factory()->create();

  Passport::actingAs($this->user);
});

it("shows the defaults when the user has no settings", function () {
  $this->getJson("/settings")->assertOk()->assertExactJson(["balance_start" => "2026-09-01", "monthly_budget" => null]);
});

it("creates the settings with the first update", function () {
  $this->putJson("/settings", ["balance_start" => "2026-10-01", "monthly_budget" => 12000.5])
    ->assertOk()->assertExactJson(["balance_start" => "2026-10-01", "monthly_budget" => 12000.5]);

  expect(Setting::where("user_id", $this->user->id)->count())->toBe(1)
    ->and($this->getJson("/settings")->json())->toBe(["balance_start" => "2026-10-01", "monthly_budget" => 12000.5]);
});

it("stores the balance start as the first of its month", function (string $sent) {
  $this->putJson("/settings", ["balance_start" => $sent])->assertOk()->assertJsonPath("balance_start", "2026-10-01");
})->with([
  "a month" => ["2026-10"],
  "the first" => ["2026-10-01"],
  "another day" => ["2026-10-17"],
]);

it("changes only the keys it receives", function () {
  $this->putJson("/settings", ["balance_start" => "2026-10-01", "monthly_budget" => 5000])->assertOk();

  $this->putJson("/settings", ["monthly_budget" => 6000])->assertOk()
    ->assertExactJson(["balance_start" => "2026-10-01", "monthly_budget" => 6000]);
  $this->putJson("/settings", ["balance_start" => "2026-11"])->assertOk()
    ->assertExactJson(["balance_start" => "2026-11-01", "monthly_budget" => 6000]);
});

it("clears the budget with null and accepts zero", function () {
  $this->putJson("/settings", ["monthly_budget" => 5000])->assertOk();
  $this->putJson("/settings", ["monthly_budget" => 0])->assertOk()->assertJsonPath("monthly_budget", 0);
  $this->putJson("/settings", ["monthly_budget" => null])->assertOk()->assertJsonPath("monthly_budget", null);
});

it("keeps the settings of each user apart", function () {
  Setting::create(["user_id" => $this->other->id, "balance_start" => "2026-01-01", "monthly_budget" => 99]);

  $this->putJson("/settings", ["monthly_budget" => 5000])->assertOk();

  expect(Setting::where("user_id", $this->other->id)->first())->balance_start->format("Y-m-d")->toBe("2026-01-01")
    ->and(Setting::where("user_id", $this->other->id)->first()->monthly_budget)->toBe(99.0);
});

it("refuses what is not valid and changes nothing", function (array $body) {
  $this->putJson("/settings", $body)->assertStatus(400);

  expect(Setting::where("user_id", $this->user->id)->count())->toBe(0);
})->with([
  "text as a month" => [["balance_start" => "october"]],
  "a month 13" => [["balance_start" => "2026-13"]],
  "a day that does not exist" => [["balance_start" => "2026-02-31"]],
  "a number as a month" => [["balance_start" => 202610]],
  "an empty month" => [["balance_start" => null]],
  "a negative budget" => [["monthly_budget" => -1]],
  "a budget in text" => [["monthly_budget" => "abc"]],
  "a valid budget with a bad month" => [["monthly_budget" => 5000, "balance_start" => "x"]],
]);
