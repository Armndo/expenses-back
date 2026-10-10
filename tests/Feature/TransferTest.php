<?php

use App\Models\Source;
use App\Models\Transfer;
use App\Models\User;
use Laravel\Passport\Passport;

beforeEach(function () {
  $this->user = User::factory()->create();
  $this->bank = Source::factory()->for($this->user)->create(["name" => "bank"]);
  $this->bank2 = Source::factory()->for($this->user)->create(["name" => "bank 2"]);
  $this->card = Source::factory()->for($this->user)->cutoff(15)->create(["name" => "card"]);
  $this->theirs = Source::factory()->create(["name" => "theirs"]);
  $this->transfer = Transfer::factory()->between($this->bank, $this->card)->on("2026-10-01")->create(["amount" => 100]);

  Passport::actingAs($this->user);
});

function transferBody($test, array $overrides = []): array {
  return [
    "from_source_id" => $test->bank->id,
    "to_source_id" => $test->card->id,
    "date" => "2026-10-05",
    "amount" => 250.5,
    "description" => "card payment",
    ...$overrides,
  ];
}

it("creates a transfer between the user's own sources", function () {
  $this->postJson("/transfers", transferBody($this))->assertCreated()->assertJsonPath("amount", 250.5);

  $created = Transfer::where("description", "card payment")->first();

  expect($created->from_source_id)->toBe($this->bank->id)
    ->and($created->to_source_id)->toBe($this->card->id)
    ->and($created->received_amount)->toBeNull();
});

it("keeps the received amount when there is one", function () {
  $this->bank2->update(["currency" => "USD"]);

  $this->postJson("/transfers", transferBody($this, ["to_source_id" => $this->bank2->id, "received_amount" => 240]))->assertCreated();

  expect(Transfer::where("description", "card payment")->first()->received_amount)->toBe(240.0);
});

it("refuses a transfer that is not valid", function (array $overrides) {
  $before = Transfer::count();

  $this->postJson("/transfers", transferBody($this, array_map(fn ($v) => $v === "THEIRS" ? $this->theirs->id : ($v === "BANK" ? $this->bank->id : $v), $overrides)))
    ->assertStatus(400);

  expect(Transfer::count())->toBe($before);
})->with([
  "to a foreign source" => [["to_source_id" => "THEIRS"]],
  "from a foreign source" => [["from_source_id" => "THEIRS"]],
  "to the same source" => [["to_source_id" => "BANK"]],
  "without destination" => [["to_source_id" => null]],
  "without origin" => [["from_source_id" => null]],
  "zero amount" => [["amount" => 0]],
  "negative amount" => [["amount" => -5]],
  "text amount" => [["amount" => "abc"]],
  "no amount" => [["amount" => null]],
  "bad date" => [["date" => "not a date"]],
  "no date" => [["date" => null]],
  "zero received amount" => [["received_amount" => 0]],
  "text received amount" => [["received_amount" => "abc"]],
]);

it("updates a transfer", function () {
  $this->putJson("/transfers/{$this->transfer->id}", ["amount" => 120, "description" => "changed", "date" => "2026-10-09"])->assertOk();

  $transfer = $this->transfer->fresh();

  expect($transfer->amount)->toBe(120.0)
    ->and($transfer->description)->toBe("changed")
    ->and($transfer->date)->toBe("2026-10-09");
});

it("moves a transfer between the user's own sources and clears the received amount with null", function () {
  $this->transfer->update(["received_amount" => 90]);

  $this->putJson("/transfers/{$this->transfer->id}", ["to_source_id" => $this->bank2->id, "received_amount" => null])->assertOk();

  expect($this->transfer->fresh()->to_source_id)->toBe($this->bank2->id)
    ->and($this->transfer->fresh()->received_amount)->toBeNull();
});

it("refuses to update a transfer into something that is not valid", function (array $body) {
  $body = array_map(fn ($v) => $v === "THEIRS" ? $this->theirs->id : ($v === "SAME" ? $this->bank->id : $v), $body);

  $this->putJson("/transfers/{$this->transfer->id}", $body)->assertStatus(400);

  $transfer = $this->transfer->fresh();

  expect([$transfer->from_source_id, $transfer->to_source_id, $transfer->amount])->toBe([$this->bank->id, $this->card->id, 100.0]);
})->with([
  "foreign destination" => [["to_source_id" => "THEIRS"]],
  "same source on both ends" => [["to_source_id" => "SAME"]],
  "zero amount" => [["amount" => 0]],
  "bad date" => [["date" => "nope"]],
]);

it("deletes a transfer", function () {
  $this->deleteJson("/transfers/{$this->transfer->id}")->assertOk();

  expect(Transfer::find($this->transfer->id))->toBeNull();
});

it("refuses to update or delete a missing, foreign or half-foreign transfer", function () {
  $foreign = Transfer::factory()->between($this->theirs, Source::factory()->create())->create(["amount" => 5]);
  $half = Transfer::factory()->between($this->bank, $this->theirs)->create(["amount" => 7]);

  foreach ([999999, $foreign->id, $half->id] as $id) {
    $this->putJson("/transfers/$id", ["amount" => 1])->assertStatus(400);
    $this->deleteJson("/transfers/$id")->assertStatus(400);
  }

  expect($foreign->fresh()->amount)->toBe(5.0)->and($half->fresh()->amount)->toBe(7.0);
});

it("needs the received amount between currencies", function () {
  $this->bank2->update(["currency" => "USD"]);

  $this->postJson("/transfers", transferBody($this, ["from_source_id" => $this->bank2->id, "to_source_id" => $this->bank->id]))->assertStatus(400);
  $this->postJson("/transfers", transferBody($this, ["from_source_id" => $this->bank2->id, "to_source_id" => $this->bank->id, "received_amount" => 0]))->assertStatus(400);
  $this->postJson("/transfers", transferBody($this, ["from_source_id" => $this->bank2->id, "to_source_id" => $this->bank->id, "received_amount" => 1780]))->assertCreated();
});

it("refuses a received amount within one currency", function () {
  $before = Transfer::count();

  $this->postJson("/transfers", transferBody($this, ["received_amount" => 240]))->assertStatus(400);

  expect(Transfer::count())->toBe($before);
});

it("checks the received amount again when a transfer changes currencies", function () {
  $this->bank2->update(["currency" => "USD"]);

  // Same-currency transfer (no received amount) moved to a dollar source.
  $this->putJson("/transfers/{$this->transfer->id}", ["to_source_id" => $this->bank2->id])->assertStatus(400);
  $this->putJson("/transfers/{$this->transfer->id}", ["to_source_id" => $this->bank2->id, "received_amount" => 5.6])->assertOk();

  expect($this->transfer->fresh()->received_amount)->toBe(5.6);

  // And back to pesos it has to drop it.
  $this->putJson("/transfers/{$this->transfer->id}", ["to_source_id" => $this->card->id])->assertStatus(400);
  $this->putJson("/transfers/{$this->transfer->id}", ["to_source_id" => $this->card->id, "received_amount" => null])->assertOk();
});

it("keeps the received amount of a conversion when only other fields change", function () {
  $this->bank2->update(["currency" => "USD"]);
  $this->transfer->update(["to_source_id" => $this->bank2->id, "received_amount" => 5.6]);

  $this->putJson("/transfers/{$this->transfer->id}", ["description" => "usd"])->assertOk();

  expect($this->transfer->fresh()->received_amount)->toBe(5.6);
});
