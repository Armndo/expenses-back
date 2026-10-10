<?php

use App\Models\Source;
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

it("only changes the currency", function () {
  $this->putJson("/sources/{$this->source->id}", ["currency" => "USD", "name" => "other", "cutoff" => 5])->assertOk();

  $source = $this->source->fresh();

  expect([$source->name, $source->cutoff])->toBe(["dollar app", null]);
});
