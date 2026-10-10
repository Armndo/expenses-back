<?php

use App\Models\Category;
use App\Models\Expense;
use App\Models\Source;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Passport\Passport;

/** The source called $name as GET /data returns it for the month of $month. */
function dataSource($test, string $month, int $source_id): array {
  return collect($test->getJson("/data?date=$month")->assertOk()->json("expenses"))->firstWhere("id", $source_id);
}

/** Ids of the cash expenses of a source in a month. */
function cashIds($test, string $month, Source $source): array {
  return collect(dataSource($test, $month, $source->id)["expenses"])->pluck("id")->all();
}

/** Ids of the instalments of a source in a month. */
function instalmentIds($test, string $month, Source $source): array {
  return collect(dataSource($test, $month, $source->id)["instalments"])->pluck("id")->all();
}

/** The months (of Aug-Dec 2026) in which the expense is listed, as numbers. */
function monthsOf($test, Expense $expense, Source $source): array {
  return collect([8, 9, 10, 11, 12])->filter(function ($m) use ($test, $expense, $source) {
    $data = dataSource($test, sprintf("2026-%02d-01", $m), $source->id);

    return collect([...$data["expenses"], ...$data["instalments"]])->pluck("id")->contains($expense->id);
  })->values()->all();
}

beforeEach(function () {
  $this->user = User::factory()->create();
  $this->none = Source::factory()->for($this->user)->create(["name" => "no cutoff"]);
  $this->zero = Source::factory()->for($this->user)->cutoff(0)->create(["name" => "cutoff 0"]);
  $this->c15 = Source::factory()->for($this->user)->cutoff(15)->create(["name" => "cutoff 15"]);

  Passport::actingAs($this->user);
});

describe("billing period of a cash expense", function () {
  it("is the calendar month with no cutoff or a zero cutoff", function (string $name) {
    $source = $this->$name;
    $before = Expense::factory()->for($source)->on("2026-09-30")->create();
    $first = Expense::factory()->for($source)->on("2026-10-01")->create();
    $last = Expense::factory()->for($source)->on("2026-10-31")->create();
    $after = Expense::factory()->for($source)->on("2026-11-01")->create();

    expect(cashIds($this, "2026-10-01", $source))->toEqualCanonicalizing([$first->id, $last->id])
      ->and(cashIds($this, "2026-09-01", $source))->toBe([$before->id])
      ->and(cashIds($this, "2026-11-01", $source))->toBe([$after->id]);
  })->with(["none", "zero"]);

  it("runs from the day after the cutoff to the cutoff of the next month", function () {
    // October with cutoff 15 is Oct 16 - Nov 15.
    $a = Expense::factory()->for($this->c15)->on("2026-10-15")->create();
    $b = Expense::factory()->for($this->c15)->on("2026-10-16")->create();
    $c = Expense::factory()->for($this->c15)->on("2026-11-15")->create();
    $d = Expense::factory()->for($this->c15)->on("2026-11-16")->create();

    expect(cashIds($this, "2026-09-01", $this->c15))->toBe([$a->id])
      ->and(cashIds($this, "2026-10-01", $this->c15))->toEqualCanonicalizing([$b->id, $c->id])
      ->and(cashIds($this, "2026-11-01", $this->c15))->toBe([$d->id]);
  });

  it("moves a billed-next expense to the following period", function () {
    $plain = Expense::factory()->for($this->none)->on("2026-09-30")->create();
    $next = Expense::factory()->for($this->none)->on("2026-09-30")->next()->create();
    $nextCut = Expense::factory()->for($this->c15)->on("2026-10-15")->next()->create();   // effective Nov 15

    expect(monthsOf($this, $plain, $this->none))->toBe([9])
      ->and(monthsOf($this, $next, $this->none))->toBe([10])
      ->and(monthsOf($this, $nextCut, $this->c15))->toBe([10]);   // Oct period is Oct 16 - Nov 15
  });
});

describe("instalments", function () {
  it("are active in each of their N periods from the effective date", function () {
    $three = Expense::factory()->for($this->none)->on("2026-09-10")->instalments(3)->create();
    $firstDay = Expense::factory()->for($this->none)->on("2026-09-01")->instalments(2)->create();
    $lastDay = Expense::factory()->for($this->none)->on("2026-09-30")->instalments(2)->create();

    expect(monthsOf($this, $three, $this->none))->toBe([9, 10, 11])
      ->and(monthsOf($this, $firstDay, $this->none))->toBe([9, 10])
      ->and(monthsOf($this, $lastDay, $this->none))->toBe([9, 10]);
  });

  it("start a period later when billed next", function () {
    $next = Expense::factory()->for($this->none)->on("2026-08-31")->next()->instalments(2)->create();   // effective Sep 30

    expect(monthsOf($this, $next, $this->none))->toBe([9, 10]);
  });

  it("follow the cutoff of their source", function () {
    $cut = Expense::factory()->for($this->c15)->on("2026-09-20")->instalments(2)->create();       // Sep 16 - Oct 15, then Oct 16 - Nov 15
    $edge = Expense::factory()->for($this->c15)->on("2026-09-15")->instalments(2)->create();      // Aug 16 - Sep 15

    expect(monthsOf($this, $cut, $this->c15))->toBe([9, 10])
      ->and(monthsOf($this, $edge, $this->c15))->toBe([8, 9]);
  });

  it("are listed apart from the cash expenses, each with its own count", function () {
    $cash1 = Expense::factory()->for($this->none)->on("2026-10-02")->create();
    $cash2 = Expense::factory()->for($this->none)->on("2026-10-03")->create();
    $inst = Expense::factory()->for($this->none)->on("2026-10-04")->instalments(6)->create();

    $source = dataSource($this, "2026-10-01", $this->none->id);

    expect(collect($source["expenses"])->pluck("id")->all())->toEqualCanonicalizing([$cash1->id, $cash2->id])
      ->and(collect($source["instalments"])->pluck("id")->all())->toBe([$inst->id])
      ->and([$source["expenses_count"], $source["instalments_count"]])->toBe([2, 1]);
  });
});

describe("categories", function () {
  it("sum their expenses, each instalment contributing its monthly share", function () {
    $cat = Category::factory()->create();
    $empty = Category::factory()->create();
    Expense::factory()->for($this->none)->on("2026-10-02")->create(["amount" => 10, "category_id" => $cat->id]);
    Expense::factory()->for($this->c15)->on("2026-10-20")->create(["amount" => 20, "category_id" => $cat->id]);
    Expense::factory()->for($this->none)->on("2026-10-03")->instalments(3)->create(["amount" => 90, "category_id" => $cat->id]);
    Expense::factory()->for($this->none)->on("2026-11-03")->create(["amount" => 1000, "category_id" => $cat->id]);   // another month
    Expense::factory()->for($this->none)->on("2026-10-04")->create(["amount" => 5000]);                              // no category

    $categories = collect($this->getJson("/data?date=2026-10-01")->json("categories"))->keyBy("id");

    expect($categories[$cat->id]["expenses_count"])->toBe(3)
      ->and($categories[$cat->id]["expenses_sum_amount"])->toEqual(60)     // 10 + 20 + 90 / 3
      ->and($categories[$empty->id]["expenses_count"])->toBe(0)
      ->and($categories[$empty->id]["expenses_sum_amount"])->toEqual(0)
      ->and($categories[$cat->id])->not->toHaveKey("expenses");
  });

  it("ignore the expenses of other users", function () {
    $cat = Category::factory()->create();
    Expense::factory()->for($this->none)->on("2026-10-02")->create(["amount" => 10, "category_id" => $cat->id]);
    Expense::factory()->for(Source::factory())->on("2026-10-02")->create(["amount" => 777, "category_id" => $cat->id]);

    $category = collect($this->getJson("/data?date=2026-10-01")->json("categories"))->firstWhere("id", $cat->id);

    expect($category["expenses_count"])->toBe(1)
      ->and($category["expenses_sum_amount"])->toEqual(10);
  });

  it("are ordered by order, then name", function () {
    $b = Category::factory()->create(["name" => "bbb", "order" => 2]);
    $a2 = Category::factory()->create(["name" => "aaa", "order" => 2]);
    $first = Category::factory()->create(["name" => "zzz", "order" => 1]);

    $ids = collect($this->getJson("/data")->json("categories"))->pluck("id");
    $mine = $ids->filter(fn ($id) => in_array($id, [$b->id, $a2->id, $first->id]))->values()->all();

    expect($mine)->toBe([$first->id, $a2->id, $b->id]);
  });

  it("agree with the sources' own lists for every period", function () {
    $cat = Category::factory()->create();
    $sources = [$this->none, $this->zero, $this->c15];
    $dates = ["2026-08-31", "2026-09-01", "2026-09-15", "2026-09-16", "2026-10-14", "2026-10-15", "2026-10-31", "2026-11-01"];

    foreach ($sources as $source) {
      foreach ($dates as $date) {
        foreach ([[false, null], [true, null], [false, 3], [true, 2]] as [$next, $instalments]) {
          Expense::factory()->for($source)->on($date)->create(["next" => $next, "instalments" => $instalments, "category_id" => $cat->id, "amount" => fake()->randomFloat(2, 1, 500)]);
        }
      }
    }

    foreach (["2026-07-01", "2026-08-01", "2026-09-01", "2026-10-01", "2026-11-01", "2026-12-01", "2027-01-01"] as $month) {
      $json = $this->getJson("/data?date=$month")->assertOk()->json();
      $listed = collect($json["expenses"])->flatMap(fn ($s) => [...$s["expenses"], ...$s["instalments"]]);
      $category = collect($json["categories"])->firstWhere("id", $cat->id);

      expect($category["expenses_count"])->toBe($listed->count(), $month)
        ->and($category["expenses_sum_amount"])->toEqualWithDelta($listed->sum(fn ($e) => $e["amount"] / ($e["instalments"] ?? 1)), 0.001, $month);
    }
  });
});

describe("the month", function () {
  it("lists every source of the user, even without movements, and nobody else's", function () {
    $theirs = Source::factory()->create();
    Expense::factory()->for($theirs)->on("2026-10-02")->create();

    $source = dataSource($this, "2026-10-01", $this->none->id);
    $ids = collect($this->getJson("/data?date=2026-10-01")->json("expenses"))->pluck("id")->all();

    expect($ids)->toBe([$this->none->id, $this->zero->id, $this->c15->id])
      ->and([$source["expenses_count"], $source["instalments_count"], $source["incomes_count"]])->toBe([0, 0, 0]);
  });

  it("orders expenses by date and then id, both descending", function () {
    $old = Expense::factory()->for($this->none)->on("2026-10-01")->create();
    $sameDayA = Expense::factory()->for($this->none)->on("2026-10-05")->create();
    $sameDayB = Expense::factory()->for($this->none)->on("2026-10-05")->create();
    $recent = Expense::factory()->for($this->none)->on("2026-10-09")->create();

    expect(cashIds($this, "2026-10-01", $this->none))->toBe([$recent->id, $sameDayB->id, $sameDayA->id, $old->id]);
  });

  it("is the one of the date with the day normalized to the 1st", function () {
    $expense = Expense::factory()->for($this->none)->on("2026-10-25")->create();
    Expense::factory()->for($this->none)->on("2026-11-02")->create();

    expect(cashIds($this, "2026-10-17", $this->none))->toBe([$expense->id])
      ->and(cashIds($this, "2026-10-31", $this->none))->toBe([$expense->id])
      ->and(cashIds($this, "2026-10-01", $this->none))->toBe([$expense->id]);
  });

  it("is the current one when no date is given", function () {
    Carbon::setTestNow("2026-10-20 12:00:00");
    $expense = Expense::factory()->for($this->none)->on("2026-10-02")->create();
    Expense::factory()->for($this->none)->on("2026-09-02")->create();

    $source = collect($this->getJson("/data")->assertOk()->json("expenses"))->firstWhere("id", $this->none->id);

    expect(collect($source["expenses"])->pluck("id")->all())->toBe([$expense->id]);

    Carbon::setTestNow();
  });

  it("does not expose source_id, timestamps or the owner on expenses", function () {
    Expense::factory()->for($this->none)->on("2026-10-02")->create();

    $expense = dataSource($this, "2026-10-01", $this->none->id)["expenses"][0];

    expect(array_keys($expense))->toEqualCanonicalizing(["id", "amount", "description", "date", "next", "instalments", "category_id"]);
  });
});
