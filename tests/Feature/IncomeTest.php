<?php

use App\Models\Income;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Passport;

// Same setup as AuthTokenTest: DB_CONNECTION=pgsql_local DB_DATABASE_LOCAL=expenses_test
uses(DatabaseTransactions::class);

function incomeUser(): User {
  return User::factory()->create(["username" => fake()->unique()->userName(), "lastname" => fake()->lastName()]);
}

/** Ids of the incomes /data returns for the first source in the month of $date. */
function incomeIds($test, string $date, int $source_id): array {
  $source = collect($test->getJson("/data?date=$date")->assertOk()->json("expenses"))->firstWhere("id", $source_id);

  expect($source["incomes_count"])->toBe(count($source["incomes"]));

  return collect($source["incomes"])->pluck("id")->all();
}

beforeEach(function () {
  if (DB::connection()->getDatabaseName() !== "expenses_test") {
    $this->markTestSkipped("Set DB_DATABASE_LOCAL=expenses_test (see top of file).");
  }

  $this->user = incomeUser();
  $this->mine = $this->user->sources()->create(["name" => "mine"]);
  $this->myOther = $this->user->sources()->create(["name" => "mine 2"]);
  $this->theirs = incomeUser()->sources()->create(["name" => "theirs"]);
  $this->income = $this->mine->incomes()->create(["date" => "2026-10-01", "amount" => 100, "description" => "salary"]);

  Passport::actingAs($this->user);
});

it("creates an income in the user's source", function () {
  $this->postJson("/incomes", ["source_id" => $this->mine->id, "date" => "2026-10-05", "amount" => 50, "description" => "gift"])
    ->assertCreated()
    ->assertJsonPath("amount", 50);

  expect($this->mine->incomes()->count())->toBe(2);
});

it("refuses to create an income in another user's source", function () {
  $this->postJson("/incomes", ["source_id" => $this->theirs->id, "date" => "2026-10-05", "amount" => 50])->assertStatus(400);

  expect($this->theirs->incomes()->count())->toBe(0);
});

it("refuses an income without a valid amount or date", function ($body) {
  $this->postJson("/incomes", ["source_id" => $this->mine->id, ...$body])->assertStatus(400);

  expect($this->mine->incomes()->count())->toBe(1);
})->with([
  "zero amount" => [["date" => "2026-10-05", "amount" => 0]],
  "text amount" => [["date" => "2026-10-05", "amount" => "abc"]],
  "no amount" => [["date" => "2026-10-05"]],
  "no date" => [["amount" => 10]],
  "bad date" => [["date" => "not a date", "amount" => 10]],
]);

it("updates an income and moves it between the user's own sources", function () {
  $this->putJson("/incomes/{$this->income->id}", ["amount" => 120, "source_id" => $this->myOther->id])->assertOk();

  expect($this->income->fresh()->amount)->toBe(120.0)
    ->and($this->income->fresh()->source_id)->toBe($this->myOther->id);
});

it("refuses to move an income into another user's source", function () {
  $this->putJson("/incomes/{$this->income->id}", ["source_id" => $this->theirs->id])->assertStatus(400);

  expect($this->income->fresh()->source_id)->toBe($this->mine->id);
});

it("refuses to update an income to a zero amount", function () {
  $this->putJson("/incomes/{$this->income->id}", ["amount" => 0])->assertStatus(400);

  expect($this->income->fresh()->amount)->toBe(100.0);
});

it("refuses to update or delete a missing or foreign income", function () {
  $foreign = $this->theirs->incomes()->create(["date" => "2026-10-01", "amount" => 5]);

  $this->putJson("/incomes/999999", ["amount" => 1])->assertStatus(400);
  $this->putJson("/incomes/{$foreign->id}", ["amount" => 1])->assertStatus(400);
  $this->deleteJson("/incomes/999999")->assertStatus(400);
  $this->deleteJson("/incomes/{$foreign->id}")->assertStatus(400);

  expect($foreign->fresh()->amount)->toBe(5.0);
});

it("deletes an income", function () {
  $this->deleteJson("/incomes/{$this->income->id}")->assertOk();

  expect(Income::find($this->income->id))->toBeNull();
});

it("bills incomes by the calendar month when the source has no cutoff or a zero cutoff", function ($cutoff) {
  $this->mine->update(["cutoff" => $cutoff]);
  $this->income->delete();

  $before = $this->mine->incomes()->create(["date" => "2026-09-30", "amount" => 1]);
  $first = $this->mine->incomes()->create(["date" => "2026-10-01", "amount" => 1]);
  $last = $this->mine->incomes()->create(["date" => "2026-10-31", "amount" => 1]);
  $after = $this->mine->incomes()->create(["date" => "2026-11-01", "amount" => 1]);

  expect(incomeIds($this, "2026-10-01", $this->mine->id))->toBe([$first->id, $last->id])
    ->and(incomeIds($this, "2026-09-01", $this->mine->id))->toBe([$before->id])
    ->and(incomeIds($this, "2026-11-01", $this->mine->id))->toBe([$after->id]);
})->with([null, 0]);

it("bills incomes by the source cutoff", function () {
  $this->mine->update(["cutoff" => 15]);
  $this->income->delete();

  $a = $this->mine->incomes()->create(["date" => "2026-10-15", "amount" => 1]);
  $b = $this->mine->incomes()->create(["date" => "2026-10-16", "amount" => 1]);
  $c = $this->mine->incomes()->create(["date" => "2026-11-15", "amount" => 1]);
  $d = $this->mine->incomes()->create(["date" => "2026-11-16", "amount" => 1]);

  // October's period runs from Oct 16 to Nov 15, as it does for expenses.
  expect(incomeIds($this, "2026-09-01", $this->mine->id))->toBe([$a->id])
    ->and(incomeIds($this, "2026-10-01", $this->mine->id))->toBe([$b->id, $c->id])
    ->and(incomeIds($this, "2026-11-01", $this->mine->id))->toBe([$d->id]);
});

it("puts an income and an expense with the same date in the same month", function () {
  $this->mine->update(["cutoff" => 10]);
  $this->income->delete();

  foreach (["2026-10-09", "2026-10-10", "2026-11-09", "2026-11-10"] as $date) {
    $income = $this->mine->incomes()->create(["date" => $date, "amount" => 1]);
    $expense = $this->mine->expenses()->create(["date" => $date, "amount" => 1]);

    foreach (["2026-09-01", "2026-10-01", "2026-11-01", "2026-12-01"] as $month) {
      $source = collect($this->getJson("/data?date=$month")->json("expenses"))->firstWhere("id", $this->mine->id);

      expect(in_array($income->id, collect($source["incomes"])->pluck("id")->all()))
        ->toBe(in_array($expense->id, collect($source["expenses"])->pluck("id")->all()), "$date in $month");
    }
  }
});

it("only returns the user's own incomes from /data", function () {
  $this->theirs->incomes()->create(["date" => "2026-10-05", "amount" => 9]);

  $ids = collect($this->getJson("/data?date=2026-10-01")->json("expenses"))->pluck("id")->all();

  expect($ids)->toBe([$this->mine->id, $this->myOther->id]);
});
