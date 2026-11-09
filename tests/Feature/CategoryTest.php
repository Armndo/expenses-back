<?php

use App\Models\Category;
use App\Models\Expense;
use App\Models\Source;
use App\Models\User;
use Laravel\Passport\Passport;

beforeEach(function () {
  $this->user = User::factory()->create();
  $this->category = Category::factory()->for($this->user)->create(["name" => "Food", "alias" => "🍔", "color" => "#aabbcc", "order" => 3]);
  $this->theirs = Category::factory()->create(["name" => "Food"]);

  Passport::actingAs($this->user);
});

it("creates a category of the user", function () {
  $response = $this->postJson("/categories", ["name" => " Pets ", "alias" => "🐈", "color" => "#00FF7f", "order" => 7])->assertOk();

  $response->assertJsonPath("name", "Pets")->assertJsonPath("alias", "🐈")->assertJsonPath("color", "#00FF7f")->assertJsonPath("order", 7)->assertJsonPath("disabled", false);

  expect(Category::find($response->json("id"))->user_id)->toBe($this->user->id)
    ->and($response->json())->not->toHaveKey("user_id");
});

it("creates a category with only a name", function () {
  $this->postJson("/categories", ["name" => "Misc"])->assertOk()->assertJsonPath("alias", null)->assertJsonPath("color", null)->assertJsonPath("order", null);
});

it("refuses a category that is not valid", function (array $body) {
  $this->postJson("/categories", $body)->assertStatus(400);

  expect($this->user->categories()->count())->toBe(1);
})->with([
  "no name" => [[]],
  "blank name" => [["name" => "  "]],
  "a name that is too long" => [["name" => str_repeat("a", 51)]],
  "the same name in another case" => [["name" => "food"]],
  "an alias that is too long" => [["name" => "x", "alias" => "123456789"]],
  "an alias that is a list" => [["name" => "x", "alias" => ["🍔"]]],
  "a colour without the hash" => [["name" => "x", "color" => "aabbcc"]],
  "a colour that is too short" => [["name" => "x", "color" => "#abc"]],
  "a colour that is not hexadecimal" => [["name" => "x", "color" => "#gggggg"]],
  "a negative order" => [["name" => "x", "order" => -1]],
  "an order of 256" => [["name" => "x", "order" => 256]],
  "an order with decimals" => [["name" => "x", "order" => 1.5]],
  "an order that is text" => [["name" => "x", "order" => "x"]],
  "a disabled that is not a boolean" => [["name" => "x", "disabled" => "maybe"]],
]);

it("allows the same name as another user's category", function () {
  $this->postJson("/categories", ["name" => $this->theirs->name . "2"])->assertOk();
  $this->postJson("/categories", ["name" => "Rent"])->assertOk();
});

it("changes only what is sent", function () {
  $this->putJson("/categories/{$this->category->id}", ["order" => 9])->assertOk();

  $category = $this->category->fresh();

  expect([$category->name, $category->alias, $category->color, $category->order])->toBe(["Food", "🍔", "#aabbcc", 9]);
});

it("clears the alias, the colour and the order with null", function () {
  $this->putJson("/categories/{$this->category->id}", ["alias" => null, "color" => null, "order" => null])->assertOk();

  $category = $this->category->fresh();

  expect([$category->alias, $category->color, $category->order])->toBe([null, null, null]);
});

it("refuses to rename a category to a name the user already has", function () {
  $other = Category::factory()->for($this->user)->create(["name" => "Other"]);

  $this->putJson("/categories/{$other->id}", ["name" => "FOOD"])->assertStatus(400);
  $this->putJson("/categories/{$other->id}", ["name" => "OTHER"])->assertOk();
});

it("refuses another user's category and one that does not exist", function () {
  $this->putJson("/categories/{$this->theirs->id}", ["name" => "mine now"])->assertStatus(400);
  $this->putJson("/categories/999999", ["name" => "nothing"])->assertStatus(400);

  expect($this->theirs->fresh()->name)->toBe("Food");
});

it("disables and enables a category without touching its expenses", function () {
  $source = Source::factory()->for($this->user)->create();
  $expense = Expense::factory()->for($source)->on("2026-10-05")->create(["amount" => 25, "category_id" => $this->category->id]);

  $this->putJson("/categories/{$this->category->id}", ["disabled" => true])->assertOk()->assertJsonPath("disabled", true);

  expect($expense->fresh()->category_id)->toBe($this->category->id)
    ->and($this->category->fresh()->disabled)->toBeTrue();

  $this->putJson("/categories/{$this->category->id}", ["disabled" => false])->assertOk()->assertJsonPath("disabled", false);
});

it("has no route to delete a category or a source", function () {
  $this->deleteJson("/categories/{$this->category->id}")->assertStatus(405);
  $this->deleteJson("/sources/1")->assertStatus(405);
});

it("lists only the user's categories in /data, disabled ones included", function () {
  $this->category->forceFill(["disabled" => true])->save();

  $categories = collect($this->getJson("/data?date=2026-10-01")->json("categories"));

  expect($categories->pluck("id")->all())->toBe([$this->category->id])
    ->and($categories->first())->toMatchArray(["disabled" => true, "name" => "Food"])
    ->and($categories->first())->not->toHaveKey("user_id");
});

it("keeps the sums of a disabled category in /data", function () {
  $source = Source::factory()->for($this->user)->create();
  Expense::factory()->for($source)->on("2026-10-05")->create(["amount" => 25, "category_id" => $this->category->id]);
  $this->category->forceFill(["disabled" => true])->save();

  $category = collect($this->getJson("/data?date=2026-10-01")->json("categories"))->firstWhere("id", $this->category->id);

  expect($category["expenses_count"])->toBe(1)->and($category["expenses_sum_amount"])->toEqual(25);
});
