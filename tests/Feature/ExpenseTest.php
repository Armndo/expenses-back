<?php

use App\Models\Category;
use App\Models\Expense;
use App\Models\Source;
use App\Models\User;
use Laravel\Passport\Passport;

beforeEach(function () {
  $this->user = User::factory()->create();
  $this->mine = Source::factory()->for($this->user)->create(["name" => "mine"]);
  $this->myOther = Source::factory()->for($this->user)->create(["name" => "mine 2"]);
  $this->theirs = Source::factory()->create(["name" => "theirs"]);
  $this->category = Category::factory()->create();
  $this->expense = Expense::factory()->for($this->mine)->on("2026-10-01")->create(["amount" => 10]);

  Passport::actingAs($this->user);
});

it("creates an expense in the user's source", function () {
  $this->postJson("/expenses", [
    "source_id" => $this->mine->id,
    "date" => "2026-10-05",
    "amount" => 99.5,
    "description" => "dinner",
    "next" => true,
    "instalments" => 3,
    "category_id" => $this->category->id,
  ])->assertCreated();

  $created = $this->mine->expenses()->where("description", "dinner")->first();

  expect($created->amount)->toBe(99.5)
    ->and($created->next)->toBeTrue()
    ->and($created->instalments)->toBe(3)
    ->and($created->category_id)->toBe($this->category->id);
});

it("refuses to create an expense in another user's source or without a source", function ($source) {
  $id = $source === "theirs" ? $this->theirs->id : null;

  $this->postJson("/expenses", ["source_id" => $id, "date" => "2026-10-05", "amount" => 5])->assertStatus(400);

  expect(Expense::whereIn("source_id", [$this->mine->id, $this->myOther->id, $this->theirs->id])->count())->toBe(1);
})->with(["theirs", "none"]);

it("answers 400 when the database rejects the data", function () {
  $this->postJson("/expenses", ["source_id" => $this->mine->id, "date" => "2026-10-05", "amount" => "abc"])->assertStatus(400);
});

it("updates an expense", function () {
  $this->putJson("/expenses/{$this->expense->id}", ["amount" => 25, "description" => "changed", "next" => true])->assertOk();

  $expense = $this->expense->fresh();

  expect($expense->amount)->toBe(25.0)
    ->and($expense->description)->toBe("changed")
    ->and($expense->next)->toBeTrue();
});

it("clears the instalments and the category with null", function () {
  $this->expense->update(["instalments" => 3, "category_id" => $this->category->id]);

  $this->putJson("/expenses/{$this->expense->id}", ["instalments" => null, "category_id" => null])->assertOk();

  expect($this->expense->fresh()->instalments)->toBeNull()
    ->and($this->expense->fresh()->category_id)->toBeNull();
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

it("deletes an expense", function () {
  $this->deleteJson("/expenses/{$this->expense->id}")->assertOk();

  expect(Expense::find($this->expense->id))->toBeNull();
});

it("refuses to update or delete a missing or foreign expense", function () {
  $foreign = Expense::factory()->for($this->theirs)->create(["amount" => 5]);

  $this->putJson("/expenses/999999", ["amount" => 1])->assertStatus(400);
  $this->putJson("/expenses/{$foreign->id}", ["amount" => 1])->assertStatus(400);
  $this->deleteJson("/expenses/999999")->assertStatus(400);
  $this->deleteJson("/expenses/{$foreign->id}")->assertStatus(400);

  expect($foreign->fresh()->amount)->toBe(5.0);
});
