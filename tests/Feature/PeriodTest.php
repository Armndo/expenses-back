<?php

use App\Models\Category;
use App\Models\Source;
use App\Models\User;
use Laravel\Passport\Passport;

beforeEach(function () {
  $this->user = User::factory()->create();
  $this->account = Source::factory()->for($this->user)->create(["name" => "account"]);
  $this->card = Source::factory()->for($this->user)->cutoff(15)->create(["name" => "card 15"]);
  $this->calendar = Source::factory()->for($this->user)->cutoff(0)->create(["name" => "card 0"]);
  $this->late = Source::factory()->for($this->user)->cutoff(27)->create(["name" => "card 27"]);
  $this->category = Category::factory()->for($this->user)->create();

  Passport::actingAs($this->user);
});

function expenseBody($test, $source, string $date, array $extra = []): array {
  return array_merge(["source_id" => $source->id, "date" => $date, "amount" => 100, "description" => "x", "category_id" => $test->category->id], $extra);
}

// ---- the endpoint: GET /period

it("tells the billing month of an expense by the cutoff of its source", function (string $source, string $date, string $period) {
  $this->getJson("/period?source_id={$this->{$source}->id}&date=$date")
    ->assertOk()
    ->assertExactJson(["period" => $period, "last" => null]);
})->with([
  "a debit account is the calendar month" => ["account", "2027-01-10", "2027-01"],
  "cutoff 0 is the calendar month" => ["calendar", "2027-01-31", "2027-01"],
  "the 10th with cutoff 15 is the month before" => ["card", "2027-01-10", "2026-12"],
  "the 15th with cutoff 15 is still the month before" => ["card", "2027-01-15", "2026-12"],
  "the 16th with cutoff 15 starts the month" => ["card", "2027-01-16", "2027-01"],
  "the last day of the month with cutoff 15" => ["card", "2027-01-31", "2027-01"],
  "cutoff 27 on the 27th" => ["late", "2027-03-27", "2027-02"],
  "cutoff 27 on the 28th" => ["late", "2027-03-28", "2027-03"],
  "a day of the first of the year goes to the last year" => ["card", "2027-01-01", "2026-12"],
]);

it("moves an expense a month with next", function () {
  $this->getJson("/period?source_id={$this->calendar->id}&date=2026-12-20&next=true")
    ->assertOk()->assertExactJson(["period" => "2027-01", "last" => null]);
  $this->getJson("/period?source_id={$this->calendar->id}&date=2026-12-20&next=false")
    ->assertOk()->assertExactJson(["period" => "2026-12", "last" => null]);
  // a month added to the 31st does not overflow into the next one (January 31st becomes February 28th)
  $this->getJson("/period?source_id={$this->calendar->id}&date=2027-01-31&next=1")
    ->assertOk()->assertExactJson(["period" => "2027-02", "last" => null]);
});

it("gives the first and the last month of an instalment", function (string $source, string $date, string $next, int $count, string $first, string $last) {
  $this->getJson("/period?source_id={$this->{$source}->id}&date=$date&next=$next&instalments=$count")
    ->assertOk()->assertExactJson(["period" => $first, "last" => $last]);
})->with([
  "12 instalments across a year" => ["calendar", "2026-11-20", "false", 12, "2026-11", "2027-10"],
  "2 instalments" => ["calendar", "2026-12-05", "false", 2, "2026-12", "2027-01"],
  "with a cutoff" => ["card", "2026-11-10", "false", 3, "2026-10", "2026-12"],
  "with next" => ["calendar", "2026-11-20", "true", 3, "2026-12", "2027-02"],
]);

it("refuses what is not valid", function (string $query) {
  $this->getJson("/period?$query")->assertStatus(400);
})->with([
  "no source" => ["date=2027-01-10"],
  "no date" => ["source_id=1"],
  "a date that does not exist" => ["source_id=1&date=2027-02-30"],
  "a date written another way" => ["source_id=1&date=10/01/2027"],
  "one instalment" => ["source_id=1&date=2027-01-10&instalments=1"],
  "instalments that are not a number" => ["source_id=1&date=2027-01-10&instalments=x"],
  "next that is not a boolean" => ["source_id=1&date=2027-01-10&next=maybe"],
]);

it("refuses another user's source", function () {
  $theirs = Source::factory()->create();

  $this->getJson("/period?source_id={$theirs->id}&date=2027-01-10")->assertStatus(400);
});

// ---- the writes answer with the period

it("answers the period of an expense that is created and when it is edited", function () {
  $created = $this->postJson("/expenses", expenseBody($this, $this->card, "2027-01-10"))->assertCreated();

  expect($created->json("period"))->toBe("2026-12")->and($created->json())->not->toHaveKey("period_last");
  // the rest of the answer is what it was: the expense as saved, plus the period
  expect($created->json())->toHaveKeys(["id", "amount", "description", "date", "category_id"])->not->toHaveKey("source_id");

  $moved = $this->putJson("/expenses/{$created->json('id')}", ["date" => "2027-03-20", "source_id" => $this->calendar->id])->assertOk();

  expect($moved->json("period"))->toBe("2027-03");
});

it("answers the period and the last one of an instalment, and moves it with next", function () {
  $created = $this->postJson("/expenses", expenseBody($this, $this->calendar, "2026-11-20", ["instalments" => 3]))->assertCreated();

  expect($created->json("period"))->toBe("2026-11")->and($created->json("period_last"))->toBe("2027-01");

  $next = $this->putJson("/expenses/{$created->json('id')}", ["next" => true])->assertOk();

  expect($next->json("period"))->toBe("2026-12")->and($next->json("period_last"))->toBe("2027-02");
});

it("answers the period of an income by the cutoff of its source", function () {
  $created = $this->postJson("/incomes", ["source_id" => $this->account->id, "date" => "2027-01-10", "amount" => 500, "description" => "pay"])->assertCreated();

  expect($created->json("period"))->toBe("2027-01");

  $this->putJson("/incomes/{$created->json('id')}", ["date" => "2027-02-28"])->assertOk()->assertJsonPath("period", "2027-02");
});

it("answers the calendar month of a transfer, whatever the cutoff of its ends", function () {
  $created = $this->postJson("/transfers", ["from_source_id" => $this->account->id, "to_source_id" => $this->card->id, "amount" => 300, "date" => "2027-01-10"])->assertCreated();

  expect($created->json("period"))->toBe("2027-01"); // an expense of that card on that day is billed in December

  $this->putJson("/transfers/{$created->json('id')}", ["date" => "2027-02-01"])->assertOk()->assertJsonPath("period", "2027-02");
});

// ---- the period is where /data shows it

it("shows an expense in /data in the month the write says", function (string $source, string $date, bool $next, ?int $instalments) {
  $body = expenseBody($this, $this->{$source}, $date, ["next" => $next, "instalments" => $instalments]);
  $created = $this->postJson("/expenses", $body)->assertCreated();
  $id = $created->json("id");
  $first = $created->json("period");
  $last = $created->json("period_last") ?? $first;

  $has = function (string $month) use ($id, $source) {
    $sources = collect($this->getJson("/data?date=$month-01")->assertOk()->json("expenses"));
    $mine = $sources->firstWhere("id", $this->{$source}->id);

    return collect(array_merge($mine["expenses"], $mine["instalments"]))->contains("id", $id);
  };

  expect($has($first))->toBeTrue()
    ->and($has($last))->toBeTrue()
    ->and($has(date("Y-m", strtotime("$first-01 -1 month"))))->toBeFalse()
    ->and($has(date("Y-m", strtotime("$last-01 +1 month"))))->toBeFalse();
})->with([
  "a debit account" => ["account", "2027-01-10", false, null],
  "a card before its cutoff" => ["card", "2027-01-10", false, null],
  "a card on its cutoff" => ["card", "2027-01-15", false, null],
  "a card after its cutoff" => ["card", "2027-01-16", false, null],
  "with next" => ["card", "2027-01-20", true, null],
  "an instalment" => ["calendar", "2026-11-20", false, 6],
  "an instalment with a cutoff and next" => ["card", "2026-11-10", true, 4],
  "a late cutoff at the end of the month" => ["late", "2027-01-31", true, 3],
]);

it("shows an income in /data in the month the write says", function () {
  $created = $this->postJson("/incomes", ["source_id" => $this->card->id, "date" => "2027-01-10", "amount" => 50, "description" => "refund"])->assertCreated();
  $period = $created->json("period");
  $incomesOf = fn (string $month) => collect($this->getJson("/data?date=$month-01")->json("expenses"))->firstWhere("id", $this->card->id)["incomes"];

  expect($period)->toBe("2026-12")
    ->and(collect($incomesOf($period))->contains("id", $created->json("id")))->toBeTrue()
    ->and(collect($incomesOf("2027-01"))->contains("id", $created->json("id")))->toBeFalse();
});

it("shows a transfer in /data in the month the write says", function () {
  $created = $this->postJson("/transfers", ["from_source_id" => $this->account->id, "to_source_id" => $this->card->id, "amount" => 300, "date" => "2027-01-10"])->assertCreated();
  $transfersOf = fn (string $month) => collect($this->getJson("/data?date=$month-01")->json("expenses"))->firstWhere("id", $this->account->id)["transfers"];

  expect(collect($transfersOf($created->json("period")))->contains("id", $created->json("id")))->toBeTrue()
    ->and(collect($transfersOf("2026-12"))->contains("id", $created->json("id")))->toBeFalse();
});
