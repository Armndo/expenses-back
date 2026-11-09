<?php

use App\Models\Expense;
use App\Models\Income;
use App\Models\Source;
use App\Models\Transfer;
use App\Models\User;
use Laravel\Passport\Passport;

beforeEach(function () {
  $this->user = User::factory()->create();
  $this->source = Source::factory()->for($this->user)->create(["name" => "dollar app"]);
  $this->theirs = Source::factory()->create();

  Passport::actingAs($this->user);
});

it("starts every source in pesos", function () {
  expect($this->source->fresh()->currency)->toBe("MXN");
});

it("sets the currency of a source", function () {
  $this->putJson("/sources/{$this->source->id}", ["currency" => "USD"])->assertOk()->assertJsonPath("currency", "USD");

  expect($this->source->fresh()->currency)->toBe("USD");

  $this->putJson("/sources/{$this->source->id}", ["currency" => "MXN"])->assertOk();

  expect($this->source->fresh()->currency)->toBe("MXN");
});

it("refuses a currency that is not valid", function (mixed $currency) {
  $this->putJson("/sources/{$this->source->id}", ["currency" => $currency])->assertStatus(400);

  expect($this->source->fresh()->currency)->toBe("MXN");
})->with([
  "unknown" => ["EUR"],
  "lowercase" => ["usd"],
  "empty" => [""],
  "null" => [null],
  "a list" => [["USD"]],
]);

it("refuses a source that is not the user's or does not exist", function () {
  $this->putJson("/sources/{$this->theirs->id}", ["currency" => "USD"])->assertStatus(400);
  $this->putJson("/sources/999999", ["currency" => "USD"])->assertStatus(400);

  expect($this->theirs->fresh()->currency)->toBe("MXN");
});

it("ignores the fields it does not manage", function () {
  $this->putJson("/sources/{$this->source->id}", ["currency" => "USD", "user_id" => $this->theirs->user_id, "kind" => "card"])->assertOk();

  $source = $this->source->fresh();

  expect([$source->user_id, $source->name, $source->cutoff, $source->currency])->toBe([$this->user->id, "dollar app", null, "USD"]);
});

it("creates a debit account, a card with cutoff 0 and a card with cutoff 15", function () {
  $this->postJson("/sources", ["name" => "savings"])->assertOk()->assertJsonPath("kind", "account")->assertJsonPath("cutoff", null)->assertJsonPath("currency", "MXN")->assertJsonPath("disabled", false);
  $this->postJson("/sources", ["name" => "calendar card", "cutoff" => 0])->assertOk()->assertJsonPath("kind", "card")->assertJsonPath("cutoff", 0);
  $this->postJson("/sources", ["name" => " Amex ", "cutoff" => 15, "currency" => "USD"])->assertOk()->assertJsonPath("name", "Amex")->assertJsonPath("cutoff", 15)->assertJsonPath("currency", "USD");

  expect($this->user->sources()->count())->toBe(4);
});

it("refuses a source that is not valid", function (array $body) {
  $this->postJson("/sources", $body)->assertStatus(400);

  expect($this->user->sources()->count())->toBe(1);
})->with([
  "no name" => [[]],
  "empty name" => [["name" => ""]],
  "blank name" => [["name" => "   "]],
  "a name that is not text" => [["name" => 5]],
  "a name that is too long" => [["name" => str_repeat("a", 51)]],
  "the same name in another case" => [["name" => "DOLLAR APP"]],
  "a negative cutoff" => [["name" => "x", "cutoff" => -1]],
  "a cutoff of 28" => [["name" => "x", "cutoff" => 28]],
  "a cutoff of 31" => [["name" => "x", "cutoff" => 31]],
  "a cutoff with decimals" => [["name" => "x", "cutoff" => 1.5]],
  "a cutoff that is text" => [["name" => "x", "cutoff" => "x"]],
  "a cutoff that is a list" => [["name" => "x", "cutoff" => [5]]],
  "an unknown currency" => [["name" => "x", "currency" => "EUR"]],
  "a disabled that is not a boolean" => [["name" => "x", "disabled" => "maybe"]],
]);

it("allows the same name in another user's sources", function () {
  $this->postJson("/sources", ["name" => $this->theirs->name])->assertOk();
});

it("changes only what is sent", function () {
  $card = Source::factory()->for($this->user)->cutoff(10)->create(["name" => "card", "currency" => "USD"]);

  $this->putJson("/sources/{$card->id}", ["name" => "renamed"])->assertOk();

  $card = $card->fresh();

  expect([$card->name, $card->cutoff, $card->currency, $card->disabled])->toBe(["renamed", 10, "USD", false]);
});

it("refuses to rename a source with a name the user already has", function () {
  $other = Source::factory()->for($this->user)->create(["name" => "other"]);

  $this->putJson("/sources/{$other->id}", ["name" => "Dollar App"])->assertStatus(400);
  // Its own name, in another case, is fine.
  $this->putJson("/sources/{$other->id}", ["name" => "OTHER"])->assertOk();
});

it("only changes the kind of a source while it has no movements", function () {
  $account = Source::factory()->for($this->user)->create(["name" => "account"]);

  $this->putJson("/sources/{$account->id}", ["cutoff" => 12])->assertOk()->assertJsonPath("kind", "card");
  $this->putJson("/sources/{$account->id}", ["cutoff" => null])->assertOk()->assertJsonPath("kind", "account");

  foreach (["expense" => fn($s) => Expense::factory()->for($s)->create(), "income" => fn($s) => Income::factory()->for($s)->create()] as $make) {
    $source = Source::factory()->for($this->user)->create();
    $make($source);

    $this->putJson("/sources/{$source->id}", ["cutoff" => 5])->assertStatus(400);

    expect($source->fresh()->cutoff)->toBeNull();
  }
});

it("does not let a transfer either way change the kind of a source", function () {
  $from = Source::factory()->for($this->user)->create();
  Transfer::factory()->between($from, $this->source)->create();

  $this->putJson("/sources/{$this->source->id}", ["cutoff" => 5])->assertStatus(400);
  $this->putJson("/sources/{$from->id}", ["cutoff" => 5])->assertStatus(400);
});

it("lets the cutoff of a card with movements change and moves its expenses between months", function () {
  $card = Source::factory()->for($this->user)->cutoff(15)->create(["name" => "card"]);
  // With cutoff 15 an expense of the 10th still belongs to the previous period: it is September's.
  $expense = Expense::factory()->for($card)->on("2026-10-10")->create(["amount" => 100]);

  $in = fn(string $month) => collect($this->getJson("/data?date=$month")->json("expenses"))->firstWhere("id", $card->id)["expenses"];

  expect(collect($in("2026-09-01"))->pluck("id")->all())->toBe([$expense->id])
    ->and($in("2026-10-01"))->toBe([]);

  $this->putJson("/sources/{$card->id}", ["cutoff" => 5])->assertOk()->assertJsonPath("cutoff", 5);

  expect(collect($in("2026-10-01"))->pluck("id")->all())->toBe([$expense->id])
    ->and($in("2026-09-01"))->toBe([]);
});

it("disables and enables a source", function () {
  $this->putJson("/sources/{$this->source->id}", ["disabled" => true])->assertOk()->assertJsonPath("disabled", true);

  expect($this->source->fresh()->disabled)->toBeTrue();

  $this->putJson("/sources/{$this->source->id}", ["disabled" => false])->assertOk()->assertJsonPath("disabled", false);

  expect($this->source->fresh()->disabled)->toBeFalse();
  $this->putJson("/sources/{$this->source->id}", ["disabled" => "maybe"])->assertStatus(400);
  $this->putJson("/sources/{$this->theirs->id}", ["disabled" => true])->assertStatus(400);
});

it("sends the disabled sources in /data with the flags", function () {
  $used = Source::factory()->for($this->user)->create(["name" => "used", "disabled" => true]);
  Expense::factory()->for($used)->on("2026-10-05")->create(["amount" => 40]);

  $sources = collect($this->getJson("/data?date=2026-10-01")->json("expenses"))->keyBy("id");

  expect($sources[$used->id])->toMatchArray(["disabled" => true, "has_movements" => true])
    ->and($sources[$this->source->id])->toMatchArray(["disabled" => false, "has_movements" => false])
    ->and($sources[$used->id])->not->toHaveKeys(["has_expenses", "has_incomes", "has_outgoing", "has_incoming"]);
});

it("counts the movements of any month, not only the one shown", function () {
  Income::factory()->for($this->source)->on("2025-01-10")->create();

  $source = collect($this->getJson("/data?date=2026-10-01")->json("expenses"))->firstWhere("id", $this->source->id);

  expect($source["has_movements"])->toBeTrue();
});

it("does not change the summary or the history when a source is disabled", function () {
  $card = Source::factory()->for($this->user)->cutoff(15)->create(["name" => "card"]);
  Expense::factory()->for($card)->on("2026-10-20")->create(["amount" => 500]);
  Expense::factory()->for($card)->on("2026-10-02")->instalments(3)->create(["amount" => 300]);
  Income::factory()->for($this->source)->on("2026-10-01")->create(["amount" => 1000]);

  $before = [$this->getJson("/data?date=2026-10-01")->json("summary"), $this->getJson("/history")->json()];

  $this->putJson("/sources/{$card->id}", ["disabled" => true])->assertOk();
  $this->putJson("/sources/{$this->source->id}", ["disabled" => true])->assertOk();

  expect([$this->getJson("/data?date=2026-10-01")->json("summary"), $this->getJson("/history")->json()])->toBe($before);
});
