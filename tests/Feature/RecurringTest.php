<?php

use App\Models\Category;
use App\Models\Expense;
use App\Models\Source;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Passport\Passport;

/** The recurring charges in the month `$month` (`YYYY-MM`) of a source: `[occurrence_date => amount]`, from /data. */
function recurringChargesOf($test, string $month, Source $source): array {
  $data = $test->getJson("/data?date=$month-01")->assertOk()->json("expenses");
  $items = collect($data)->firstWhere("id", $source->id)["expenses"];

  return collect($items)->filter(fn($e) => $e["recurs_every"] !== null)->mapWithKeys(fn($e) => [$e["occurrence_date"] => $e["amount"]])->all();
}

/** The months (`YYYY-MM`) between two of them where the rule of a source has a charge, each with its date. */
function recurringMonths($test, Source $source, string $from, string $to): array {
  $found = [];

  for ($month = Carbon::parse("$from-01"); $month->lte("$to-01"); $month->addMonth()) {
    $charges = recurringChargesOf($test, $month->format("Y-m"), $source);

    if ($charges) {
      $found[$month->format("Y-m")] = array_keys($charges)[0];
    }
  }

  return $found;
}

beforeEach(function () {
  config(["expenses.balance_start" => "2026-01-01"]);
  Carbon::setTestNow("2026-11-17");

  $this->user = User::factory()->create();
  $this->account = Source::factory()->for($this->user)->create(["name" => "account"]);
  $this->card = Source::factory()->for($this->user)->cutoff(15)->create(["name" => "card 15"]);
  $this->late = Source::factory()->for($this->user)->cutoff(27)->create(["name" => "card 27"]);
  $this->calendar = Source::factory()->for($this->user)->cutoff(0)->create(["name" => "card 0"]);
  $this->category = Category::factory()->for($this->user)->create();

  Passport::actingAs($this->user);
});

afterEach(fn () => Carbon::setTestNow());

describe("the charges", function () {
  it("is billed every month from its first one, and not before", function () {
    Expense::factory()->for($this->calendar)->recurring()->on("2026-08-10")->create(["amount" => 199]);

    expect(recurringMonths($this, $this->calendar, "2026-06", "2027-01"))->toBe([
      "2026-08" => "2026-08-10", "2026-09" => "2026-09-10", "2026-10" => "2026-10-10",
      "2026-11" => "2026-11-10", "2026-12" => "2026-12-10", "2027-01" => "2027-01-10",
    ]);
  });

  it("is billed every 3 months, and every 12", function () {
    $quarterly = Expense::factory()->for($this->calendar)->recurring(3)->on("2026-01-20")->create();
    $yearly = Expense::factory()->for($this->account)->recurring(12)->on("2026-02-15")->create();

    expect(array_keys(recurringMonths($this, $this->calendar, "2026-01", "2027-06")))->toBe(["2026-01", "2026-04", "2026-07", "2026-10", "2027-01", "2027-04"]);
    expect(recurringMonths($this, $this->account, "2026-01", "2028-03"))->toBe(["2026-02" => "2026-02-15", "2027-02" => "2027-02-15", "2028-02" => "2028-02-15"]);
  });

  it("counts every N months from its date, so the 31st is back after a short month", function () {
    Expense::factory()->for($this->calendar)->recurring()->on("2027-01-31")->create();

    expect(recurringMonths($this, $this->calendar, "2027-01", "2027-05"))->toBe([
      "2027-01" => "2027-01-31", "2027-02" => "2027-02-28", "2027-03" => "2027-03-31", "2027-04" => "2027-04-30", "2027-05" => "2027-05-31",
    ]);
  });

  it("has the 29th of February in a leap year and the 28th when there is none", function () {
    Expense::factory()->for($this->calendar)->recurring(12)->on("2028-02-29")->create();

    expect(recurringMonths($this, $this->calendar, "2028-02", "2032-03"))->toBe([
      "2028-02" => "2028-02-29", "2029-02" => "2029-02-28", "2030-02" => "2030-02-28", "2031-02" => "2031-02-28", "2032-02" => "2032-02-29",
    ]);
  });

  it("falls in the period of the cutoff of its source", function (string $source, string $date, string $month) {
    Expense::factory()->for($this->{$source})->recurring()->on($date)->create();

    expect(array_key_first(recurringMonths($this, $this->{$source}, "2026-11", "2027-02")))->toBe($month);
  })->with([
    "the cutoff day belongs to the month before" => ["card", "2026-12-15", "2026-11"],
    "the day after the cutoff starts the month" => ["card", "2026-12-16", "2026-12"],
    "a cutoff of 27 on the 27th" => ["late", "2026-12-27", "2026-11"],
    "a cutoff of 27 on the 28th" => ["late", "2026-12-28", "2026-12"],
    "no cutoff is the calendar month" => ["account", "2026-12-31", "2026-12"],
    "a cutoff of 0 too" => ["calendar", "2026-12-01", "2026-12"],
  ]);

  it("keeps following the cutoff after a month of 28 days", function () {
    Expense::factory()->for($this->card)->recurring()->on("2026-12-31")->create();

    // 31st with a cutoff of 15: the period is the month of the 16th, and the charge of February is the 28th: still the month of its own.
    expect(recurringMonths($this, $this->card, "2026-12", "2027-04"))->toBe([
      "2026-12" => "2026-12-31", "2027-01" => "2027-01-31", "2027-02" => "2027-02-28", "2027-03" => "2027-03-31", "2027-04" => "2027-04-30",
    ]);
  });

  it("goes a period later with next, and keeps the date of the charge", function () {
    Expense::factory()->for($this->calendar)->recurring()->next()->on("2026-09-20")->create();

    expect(recurringMonths($this, $this->calendar, "2026-09", "2026-12"))->toBe(["2026-10" => "2026-09-20", "2026-11" => "2026-10-20", "2026-12" => "2026-11-20"]);
  });

  it("ends on the date it says, a charge on that very day included", function () {
    Expense::factory()->for($this->calendar)->recurring(1, "2026-10-10")->on("2026-08-10")->create();
    Expense::factory()->for($this->account)->recurring(1, "2026-10-09")->on("2026-08-10")->create();

    expect(array_keys(recurringMonths($this, $this->calendar, "2026-07", "2027-01")))->toBe(["2026-08", "2026-09", "2026-10"]);
    expect(array_keys(recurringMonths($this, $this->account, "2026-07", "2027-01")))->toBe(["2026-08", "2026-09"]);
  });

  it("is one charge when it ends the day it starts", function () {
    Expense::factory()->for($this->calendar)->recurring(1, "2026-08-10")->on("2026-08-10")->create();

    expect(array_keys(recurringMonths($this, $this->calendar, "2026-06", "2027-01")))->toBe(["2026-08"]);
  });

  it("comes with the plain expenses and its rule, not with the instalments", function () {
    $rule = Expense::factory()->for($this->calendar)->recurring(3, "2027-03-05")->on("2026-11-05")->create(["amount" => 450, "category_id" => $this->category->id]);

    $source = collect($this->getJson("/data?date=2026-11-01")->assertOk()->json("expenses"))->firstWhere("id", $this->calendar->id);

    expect($source["instalments"])->toBe([]);
    expect($source["expenses"][0])->toMatchArray([
      "id" => $rule->id, "amount" => 450, "recurs_every" => 3, "ends_on" => "2027-03-05", "occurrence_date" => "2026-11-05", "category_id" => $this->category->id,
    ]);
    expect($source["expenses_count"])->toBe(1);
  });

  it("counts once a month in its category", function () {
    Expense::factory()->for($this->calendar)->recurring()->on("2026-09-10")->create(["amount" => 100, "category_id" => $this->category->id]);

    foreach (["2026-09", "2026-10", "2026-12"] as $month) {
      $category = collect($this->getJson("/data?date=$month-01")->assertOk()->json("categories"))->firstWhere("id", $this->category->id);

      expect([$category["expenses_count"], $category["expenses_sum_amount"]])->toBe([1, 100]);
    }
  });

  it("does not show the charges of someone else", function () {
    $theirs = Source::factory()->for(User::factory()->create())->create();
    Expense::factory()->for($theirs)->recurring()->on("2026-09-10")->create(["amount" => 999]);

    expect($this->getJson("/data?date=2026-10-01")->json("summary.spent"))->toBe(0);
  });
});

describe("the money", function () {
  it("adds the charge to what is spent each month, and not before or after", function () {
    Expense::factory()->for($this->calendar)->recurring(1, "2026-12-10")->on("2026-10-10")->create(["amount" => 100]);

    foreach (["2026-09" => 0, "2026-10" => 100, "2026-11" => 100, "2026-12" => 100, "2027-01" => 0] as $month => $spent) {
      expect($this->getJson("/data?date=$month-01")->json("summary.spent"))->toEqual($spent, $month);
    }
  });

  it("is not a pending instalment: the balance only goes down as the charges come", function () {
    $this->account->incomes()->create(["date" => "2026-01-02", "amount" => 10000, "skip_budget" => true]);
    Expense::factory()->for($this->account)->recurring()->on("2026-10-10")->create(["amount" => 100]);

    $november = $this->getJson("/data?date=2026-11-01")->assertOk()->json();
    $january = $this->getJson("/data?date=2027-01-01")->assertOk()->json();

    expect($november["summary"]["pending_instalments"])->toBe(0);
    expect(collect($november["expenses"])->firstWhere("id", $this->account->id)["balance"])->toBe(9800);
    // October, November, December and January: four charges.
    expect(collect($january["expenses"])->firstWhere("id", $this->account->id)["balance"])->toBe(9600);
  });

  it("shows up in the months to come of /history, until it ends", function () {
    Expense::factory()->for($this->calendar)->recurring(1, "2027-01-10")->on("2026-10-10")->create(["amount" => 100]);

    $months = collect($this->getJson("/history")->assertOk()->json("months"))->keyBy(fn($m) => substr($m["month"], 0, 7));

    expect([$months["2026-12"]["spent"], $months["2027-01"]["spent"]])->toBe([100, 100]);
    expect($months->has("2027-02") ? $months["2027-02"]["spent"] : 0)->toBe(0);
  });

  it("keeps counting when the source is disabled", function () {
    Expense::factory()->for($this->calendar)->recurring()->on("2026-10-10")->create(["amount" => 100]);
    $this->calendar->forceFill(["disabled" => true])->save();

    expect($this->getJson("/data?date=2026-11-01")->json("summary.spent"))->toBe(100);
  });
});

describe("the range", function () {
  it("does not change without recurring expenses", function () {
    expect($this->getJson("/data?date=2026-11-01")->json("range.last"))->toBe("2026-11-01");
  });

  it("reaches six months ahead while a recurring expense goes on", function () {
    Expense::factory()->for($this->calendar)->recurring()->on("2026-10-10")->create();

    expect($this->getJson("/data?date=2026-11-01")->json("range.last"))->toBe("2027-05-01");
  });

  it("reaches the next charge of a yearly one even when it is further than six months", function () {
    Expense::factory()->for($this->calendar)->recurring(12)->on("2026-06-30")->create();

    expect($this->getJson("/data?date=2026-11-01")->json("range.last"))->toBe("2027-06-01");
  });

  it("does not stretch past an instalment that goes further than six months", function () {
    Expense::factory()->for($this->calendar)->recurring()->on("2026-10-10")->create();
    Expense::factory()->for($this->calendar)->instalments(12)->on("2026-10-10")->create();

    expect($this->getJson("/data?date=2026-11-01")->json("range.last"))->toBe("2027-09-01");
  });

  it("stops at the last charge of one that already ended", function () {
    Expense::factory()->for($this->calendar)->recurring(1, "2026-09-10")->on("2026-07-10")->create();

    expect($this->getJson("/data?date=2026-11-01")->json("range.last"))->toBe("2026-11-01");
  });
});

describe("saving one", function () {
  it("creates a recurring expense and says where it first bills and where it ends", function () {
    $this->postJson("/expenses", ["source_id" => $this->card->id, "date" => "2026-11-10", "amount" => 199, "recurs_every" => 1, "ends_on" => "2027-03-10"])
      ->assertCreated()
      ->assertJsonFragment(["recurs_every" => 1, "ends_on" => "2027-03-10", "period" => "2026-10", "period_last" => "2027-02"]);

    $this->postJson("/expenses", ["source_id" => $this->card->id, "date" => "2026-11-20", "amount" => 50, "recurs_every" => 12])
      ->assertCreated()->assertJsonFragment(["recurs_every" => 12, "period" => "2026-11"])->assertJsonMissing(["period_last" => "2027-10"]);
  });

  it("refuses what is not valid", function (array $extra) {
    $this->postJson("/expenses", ["source_id" => $this->card->id, "date" => "2026-11-10", "amount" => 199, ...$extra])->assertStatus(400)->assertExactJson(["error"]);
  })->with([
    "zero months" => [["recurs_every" => 0]],
    "more than five years" => [["recurs_every" => 61]],
    "not a number" => [["recurs_every" => "monthly"]],
    "a decimal" => [["recurs_every" => 1.5]],
    "with instalments" => [["recurs_every" => 1, "instalments" => 3]],
    "an end without a repetition" => [["ends_on" => "2027-03-10"]],
    "an end before the date" => [["recurs_every" => 1, "ends_on" => "2026-11-09"]],
    "an end that is not a date" => [["recurs_every" => 1, "ends_on" => "soon"]],
    "an end that does not exist" => [["recurs_every" => 1, "ends_on" => "2027-02-30"]],
  ]);

  it("does not create one on a disabled source, or on someone else's", function () {
    $this->card->forceFill(["disabled" => true])->save();
    $this->postJson("/expenses", ["source_id" => $this->card->id, "date" => "2026-11-10", "amount" => 1, "recurs_every" => 1])->assertStatus(400);

    $theirs = Source::factory()->for(User::factory()->create())->create();
    $this->postJson("/expenses", ["source_id" => $theirs->id, "date" => "2026-11-10", "amount" => 1, "recurs_every" => 1])->assertStatus(400);
  });

  it("turns a plain expense into a recurring one and back", function () {
    $expense = Expense::factory()->for($this->calendar)->on("2026-10-10")->create(["amount" => 100]);

    $this->putJson("/expenses/{$expense->id}", ["recurs_every" => 1])->assertOk();
    expect($this->getJson("/data?date=2026-12-01")->json("summary.spent"))->toBe(100);

    $this->putJson("/expenses/{$expense->id}", ["recurs_every" => null, "ends_on" => null])->assertOk();
    expect($this->getJson("/data?date=2026-12-01")->json("summary.spent"))->toBe(0);
  });

  it("refuses to end it before its date, or to make it recurring while it has instalments", function () {
    $rule = Expense::factory()->for($this->calendar)->recurring()->on("2026-10-10")->create();
    $this->putJson("/expenses/{$rule->id}", ["ends_on" => "2026-10-09"])->assertStatus(400);
    $this->putJson("/expenses/{$rule->id}", ["date" => "2026-11-10", "ends_on" => "2026-11-05"])->assertStatus(400);
    $this->putJson("/expenses/{$rule->id}", ["instalments" => 3])->assertStatus(400);

    $plain = Expense::factory()->for($this->calendar)->instalments(3)->on("2026-10-10")->create();
    $this->putJson("/expenses/{$plain->id}", ["recurs_every" => 1])->assertStatus(400);
  });

  it("ends a recurring expense by giving it an end, without touching the months that went by", function () {
    $rule = Expense::factory()->for($this->calendar)->recurring()->on("2026-09-10")->create(["amount" => 100]);

    $this->putJson("/expenses/{$rule->id}", ["ends_on" => "2026-11-30"])->assertOk()->assertJsonFragment(["period_last" => "2026-11"]);

    expect(array_keys(recurringMonths($this, $this->calendar, "2026-09", "2027-02")))->toBe(["2026-09", "2026-10", "2026-11"]);
  });

  it("changes every charge when the rule changes, the past ones too (until the scope exists)", function () {
    $rule = Expense::factory()->for($this->calendar)->recurring()->on("2026-09-10")->create(["amount" => 100]);

    $this->putJson("/expenses/{$rule->id}", ["amount" => 120])->assertOk();

    foreach (["2026-09", "2026-12"] as $month) {
      expect($this->getJson("/data?date=$month-01")->json("summary.spent"))->toBe(120);
    }
  });
});

describe("deleting one", function () {
  it("needs scope=all, and without it keeps the rule", function () {
    $rule = Expense::factory()->for($this->calendar)->recurring()->on("2026-09-10")->create(["amount" => 100]);

    $this->deleteJson("/expenses/{$rule->id}")->assertStatus(400)->assertExactJson(["error"]);
    $this->deleteJson("/expenses/{$rule->id}?scope=this")->assertStatus(400);

    expect(Expense::find($rule->id))->not->toBeNull();
    expect($this->getJson("/data?date=2026-10-01")->json("summary.spent"))->toBe(100);
  });

  it("deletes the rule, and with it every charge, with scope=all", function () {
    $rule = Expense::factory()->for($this->calendar)->recurring()->on("2026-09-10")->create(["amount" => 100]);

    $this->deleteJson("/expenses/{$rule->id}?scope=all")->assertOk();

    expect(Expense::find($rule->id))->toBeNull();
    expect($this->getJson("/data?date=2026-10-01")->json("summary.spent"))->toBe(0);
  });

  it("still deletes a plain expense without a scope", function () {
    $expense = Expense::factory()->for($this->calendar)->on("2026-09-10")->create();

    $this->deleteJson("/expenses/{$expense->id}")->assertOk();
    expect(Expense::find($expense->id))->toBeNull();
  });

  it("does not delete the rule of someone else", function () {
    $theirs = Expense::factory()->for(Source::factory()->for(User::factory()->create())->create())->recurring()->create();

    $this->deleteJson("/expenses/{$theirs->id}?scope=all")->assertStatus(400);
    expect(Expense::find($theirs->id))->not->toBeNull();
  });
});

describe("GET /period", function () {
  it("says the first month and, when it ends, the last one", function () {
    $this->getJson("/period?source_id={$this->card->id}&date=2026-11-10&recurs_every=1")->assertOk()->assertExactJson(["period" => "2026-10", "last" => null]);
    $this->getJson("/period?source_id={$this->card->id}&date=2026-11-10&recurs_every=1&ends_on=2027-03-10")->assertOk()->assertExactJson(["period" => "2026-10", "last" => "2027-02"]);
    $this->getJson("/period?source_id={$this->calendar->id}&date=2026-11-10&recurs_every=3&ends_on=2027-07-09&next=true")->assertOk()->assertExactJson(["period" => "2026-12", "last" => "2027-06"]); // its last charge is May 10th, billed a period later
  });

  it("refuses a repetition that is not valid", function (string $query) {
    $this->getJson("/period?source_id={$this->card->id}&date=2026-11-10&$query")->assertStatus(400);
  })->with([
    "zero" => ["recurs_every=0"],
    "too long" => ["recurs_every=61"],
    "with instalments" => ["recurs_every=1&instalments=3"],
    "an end without a repetition" => ["ends_on=2027-03-10"],
    "an end before the date" => ["recurs_every=1&ends_on=2026-11-09"],
    "an end that is not a date" => ["recurs_every=1&ends_on=later"],
  ]);
});

describe("GET /search", function () {
  it("gives one row per rule, with its repetition, and a type of its own", function () {
    $rule = Expense::factory()->for($this->card)->recurring(1, "2027-03-10")->on("2026-11-10")->create(["amount" => 199, "description" => "Netflix"]);
    Expense::factory()->for($this->card)->on("2026-11-11")->create(["description" => "Tacos"]);

    $rows = $this->getJson("/search?type=recurring")->assertOk()->json("data");

    expect($rows)->toHaveCount(1);
    expect($rows[0])->toMatchArray([
      "type" => "expense", "id" => $rule->id, "amount" => 199.0, "recurs_every" => 1, "ends_on" => "2027-03-10", "instalments" => null,
      "period" => "2026-10", "period_last" => "2027-02",
    ]);
    expect(collect($this->getJson("/search?type=expenses")->json("data"))->pluck("description")->all())->toBe(["Tacos"]);
    expect($this->getJson("/search?q=netflix")->json("total"))->toBe(1);
  });

  it("has no last period while the rule goes on", function () {
    Expense::factory()->for($this->card)->recurring()->on("2026-11-10")->create();

    expect($this->getJson("/search?type=recurring")->json("data.0.period_last"))->toBeNull();
  });
});
