<?php

use App\Models\Category;
use App\Models\ExchangeRate;
use App\Models\Expense;
use App\Models\Income;
use App\Models\Source;
use App\Models\Transfer;
use App\Models\User;
use Laravel\Passport\Passport;

function searchIds($test, string $query = ""): array {
  $rows = $test->getJson("/search" . ($query === "" ? "" : "?$query"))->assertOk()->json("data");

  return array_map(fn($row) => $row["type"] . ":" . $row["id"], $rows);
}

beforeEach(function () {
  $this->user = User::factory()->create();
  $this->account = Source::factory()->for($this->user)->create(["name" => "Azteca"]);
  $this->card = Source::factory()->for($this->user)->cutoff(15)->create(["name" => "Invex"]);
  $this->dollars = Source::factory()->for($this->user)->create(["name" => "Dollar App", "currency" => "USD"]);
  $this->food = Category::factory()->for($this->user)->create(["name" => "Comida", "alias" => "🍔"]);
  $this->tech = Category::factory()->for($this->user)->create(["name" => "Tecnología", "alias" => "💻"]);

  $this->tacos = Expense::factory()->for($this->card)->on("2026-11-02")->create(["amount" => 100, "description" => "Tacos al pastor", "category_id" => $this->food->id]);
  $this->laptop = Expense::factory()->for($this->card)->on("2026-10-20")->instalments(12)->create(["amount" => 14405.58, "description" => "Laptop", "category_id" => $this->tech->id]);
  $this->netflix = Expense::factory()->for($this->dollars)->on("2026-11-03")->create(["amount" => 10, "description" => "Netflix", "category_id" => null]);
  $this->salary = Income::factory()->for($this->account)->on("2026-11-05")->create(["amount" => 5000, "description" => "Nómina"]);
  $this->payment = Transfer::factory()->between($this->account, $this->card)->on("2026-11-06")->create(["amount" => 1500, "description" => "Pago tarjeta"]);

  Passport::actingAs($this->user);
});

it("lists every record once, newest billing month first", function () {
  $rows = $this->getJson("/search")->assertOk()->json("data");

  expect($rows)->toHaveCount(5);
  expect(array_column($rows, "period"))->toBe(collect(array_column($rows, "period"))->sortDesc()->values()->all());
  expect($this->getJson("/search")->json())->toMatchArray(["page" => 1, "per_page" => 50, "total" => 5, "last_page" => 1]);
});

it("gives an instalment once, with its monthly share and its first and last billing month", function () {
  $row = collect($this->getJson("/search?q=laptop")->assertOk()->json("data"))->first();

  expect($row)->toMatchArray([
    "type" => "expense", "id" => $this->laptop->id, "amount" => 14405.58, "instalments" => 12, "monthly_share" => 1200.47,
    "period" => "2026-10", "period_last" => "2027-09", "source_name" => "Invex", "category_name" => "Tecnología", "category_alias" => "💻", "currency" => "MXN",
  ]);
  expect($this->getJson("/search?q=laptop")->json("total"))->toBe(1);
});

it("gives a transfer once, with its two accounts and the calendar month", function () {
  $rows = collect($this->getJson("/search?type=transfers")->assertOk()->json("data"));

  expect($rows)->toHaveCount(1);
  expect($rows->first())->toMatchArray([
    "type" => "transfer", "id" => $this->payment->id, "source_name" => "Azteca", "to_source_name" => "Invex", "amount" => 1500.0, "period" => "2026-11", "monthly_share" => null,
  ]);
});

it("puts every record in the month where /data shows it", function () {
  foreach ($this->getJson("/search")->json("data") as $row) {
    $month = $row["period"] . "-01";
    $source = collect($this->getJson("/data?date=$month")->assertOk()->json("expenses"))->firstWhere("id", $row["source_id"]);
    $group = ["expense" => $row["instalments"] === null ? "expenses" : "instalments", "income" => "incomes", "transfer" => "transfers"][$row["type"]];

    expect(collect($source[$group])->pluck("id")->all())->toContain($row["id"]);
  }
});

it("only gives the records of the user", function () {
  $other = User::factory()->create();
  $theirs = Source::factory()->for($other)->create(["name" => "Azteca"]);
  Expense::factory()->for($theirs)->on("2026-11-02")->create(["description" => "Tacos al pastor", "amount" => 100]);
  Income::factory()->for($theirs)->on("2026-11-02")->create(["description" => "Nómina", "amount" => 5000]);
  Transfer::factory()->between($theirs, Source::factory()->for($other)->create())->on("2026-11-02")->create(["description" => "Pago tarjeta"]);

  expect($this->getJson("/search")->json("total"))->toBe(5);
  expect($this->getJson("/search?q=tacos")->json("total"))->toBe(1);
});

it("searches words in the description, category, source, other account and amount, without case or accents", function (string $query, array $expected) {
  $found = collect($this->getJson("/search?q=" . urlencode($query))->assertOk()->json("data"))->map(fn($row) => $row["type"] . ":" . $row["description"])->sort()->values()->all();

  expect($found)->toBe(collect($expected)->sort()->values()->all());
})->with([
  "the description" => ["tacos", ["expense:Tacos al pastor"]],
  "several words, in any order" => ["pastor TACOS", ["expense:Tacos al pastor"]],
  "without the accent" => ["nomina", ["income:Nómina"]],
  "with the accent when the text has none" => ["tecnología", ["expense:Laptop"]],
  "the category name" => ["comida", ["expense:Tacos al pastor"]],
  "the alias of a category" => ["💻", ["expense:Laptop"]],
  "the source" => ["dollar", ["expense:Netflix"]],
  "the other account of a transfer" => ["invex", ["expense:Tacos al pastor", "expense:Laptop", "transfer:Pago tarjeta"]],
  "the amount" => ["1500", ["transfer:Pago tarjeta"]],
  "the amount with its cents" => ["100.00", ["expense:Tacos al pastor"]],
  "nothing" => ["zzz", []],
  "a percent sign is just a character" => ["%", []],
]);

it("filters by type", function () {
  expect(searchIds($this, "type=expenses"))->toEqualCanonicalizing(["expense:{$this->tacos->id}", "expense:{$this->netflix->id}"]);
  expect(searchIds($this, "type=instalments"))->toBe(["expense:{$this->laptop->id}"]);
  expect(searchIds($this, "type=incomes"))->toBe(["income:{$this->salary->id}"]);
  expect(searchIds($this, "type=incomes,transfers"))->toEqualCanonicalizing(["income:{$this->salary->id}", "transfer:{$this->payment->id}"]);
});

it("filters by category, which only expenses have", function () {
  expect(searchIds($this, "category_id={$this->food->id}"))->toBe(["expense:{$this->tacos->id}"]);
  expect(searchIds($this, "category_id={$this->tech->id}"))->toBe(["expense:{$this->laptop->id}"]);
});

it("filters by amount in pesos against the purchase, only for expenses", function () {
  ExchangeRate::create(["user_id" => $this->user->id, "currency" => "USD", "rate" => 20]);

  // Netflix is 10 dollars = 200 pesos; the laptop counts for its 14,405.58, not for its share
  expect(searchIds($this, "type=expenses,instalments&min=150&max=1000"))->toEqualCanonicalizing(["expense:{$this->netflix->id}"]);
  expect(searchIds($this, "min=10000"))->toEqualCanonicalizing(["expense:{$this->laptop->id}", "income:{$this->salary->id}", "transfer:{$this->payment->id}"]);
  // incomes and transfers are not touched by the amount
  expect(searchIds($this, "max=50"))->toEqualCanonicalizing(["income:{$this->salary->id}", "transfer:{$this->payment->id}"]);
});

it("keeps an expense whose currency has no rate when it filters by amount", function () {
  expect(searchIds($this, "type=expenses&min=150&max=1000"))->toEqualCanonicalizing(["expense:{$this->netflix->id}"]);
  expect(searchIds($this, "type=expenses&min=150&max=1000&q=tacos"))->toBe([]);
});

it("filters by the date of the record", function () {
  expect(searchIds($this, "from=2026-11-03&to=2026-11-05"))->toEqualCanonicalizing(["expense:{$this->netflix->id}", "income:{$this->salary->id}"]);
  expect(searchIds($this, "from=2026-11-06"))->toBe(["transfer:{$this->payment->id}"]);
  expect(searchIds($this, "to=2026-10-31"))->toBe(["expense:{$this->laptop->id}"]);
});

it("pages the results 50 at a time", function () {
  Expense::factory()->count(60)->for($this->account)->on("2026-01-10")->create(["description" => "bulk"]);

  $first = $this->getJson("/search")->assertOk()->json();
  $second = $this->getJson("/search?page=2")->assertOk()->json();

  expect($first["total"])->toBe(65)->and($first["last_page"])->toBe(2)->and($first["data"])->toHaveCount(50);
  expect($second["page"])->toBe(2)->and($second["data"])->toHaveCount(15);
  expect(array_intersect(array_column($first["data"], "id"), array_column($second["data"], "id")))->toBe([]);
  expect($this->getJson("/search?page=9")->json("data"))->toBe([]);
});

it("refuses what is not valid", function (string $query) {
  $this->getJson("/search?$query")->assertStatus(400)->assertExactJson(["error"]);
})->with([
  "a page of 0" => ["page=0"],
  "a page that is not a number" => ["page=x"],
  "an unknown type" => ["type=things"],
  "a list of types with a typo" => ["type=expenses,"],
  "a category that is not a number" => ["category_id=food"],
  "a min that is not a number" => ["min=cheap"],
  "a max that is not a number" => ["max=a"],
  "a date that does not exist" => ["from=2026-02-30"],
  "a date in another format" => ["to=13/11/2026"],
  "a text that is too long" => ["q=" . "x" . "yyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyyy"],
]);
