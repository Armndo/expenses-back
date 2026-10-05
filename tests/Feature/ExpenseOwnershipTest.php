<?php

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;

// Same setup as AuthTokenTest: DB_CONNECTION=pgsql_local DB_DATABASE_LOCAL=expenses_test
uses(DatabaseTransactions::class);

function makeUser(): User {
  return User::factory()->create(["username" => fake()->unique()->userName(), "lastname" => fake()->lastName()]);
}

beforeEach(function () {
  if (DB::connection()->getDatabaseName() !== "expenses_test") {
    $this->markTestSkipped("Set DB_DATABASE_LOCAL=expenses_test (see top of file).");
  }

  $this->user = makeUser();
  $this->mine = $this->user->sources()->create(["name" => "mine"]);
  $this->myOther = $this->user->sources()->create(["name" => "mine 2"]);
  $this->theirs = makeUser()->sources()->create(["name" => "theirs"]);
  $this->expense = $this->mine->expenses()->create(["date" => "2026-10-01", "amount" => 10]);

  Passport::actingAs($this->user);
});

it("moves an expense between the user's own sources", function () {
  $this->putJson("/expenses/{$this->expense->id}", ["source_id" => $this->myOther->id])->assertOk();

  expect($this->expense->fresh()->source_id)->toBe($this->myOther->id);
});

it("refuses to move an expense into another user's source", function () {
  $this->putJson("/expenses/{$this->expense->id}", ["source_id" => $this->theirs->id])->assertStatus(400);

  expect($this->expense->fresh()->source_id)->toBe($this->mine->id);
});

it("still updates other fields without source_id", function () {
  $this->putJson("/expenses/{$this->expense->id}", ["amount" => 25])->assertOk();

  expect($this->expense->fresh()->amount)->toBe(25.0);
});

it("returns the month's expenses from /data", function () {
  $this->getJson("/data?date=2026-10-01")->assertOk()->assertJsonPath("expenses.0.expenses.0.id", $this->expense->id);
});
