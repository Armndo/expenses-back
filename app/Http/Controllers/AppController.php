<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AppController extends Controller
{
  public function __invoke(Request $request) {
    $user = User::find(Auth::user()->id);
    $date = isset($request->date) ? new Carbon($request->date) : Carbon::now();
    $date->day = 1;
    $start = $date->format("Y-m-d");
    $date->month += 1;
    $date->day -= 1;
    $end = $date->format("Y-m-d");
    $tmp = [];

    $expenses = $user->sources()
    ->with([
      "expenses" => fn(HasMany $query) =>
        $query->select("expenses.*")
        ->join("sources", "sources.id", "expenses.source_id")
        ->whereRaw(<<<SQL
          case "next" when true then date("date" + interval '1 month') else "date" end between
            date(date_trunc('month', '$start'::date)::date + coalesce(sources.cutoff, 0)) and
            date(date_trunc('month', '$start'::date)::date + interval '1 month') - 1 + coalesce(sources.cutoff, 0)
        SQL)
        ->whereNull("instalments")
        ->orderBy("date")
        ->orderBy("expenses.id")
    ])->orderBy("sources.id")
    ->get()
    ->toArray();

    $instalments = $user->sources()->with([
      "expenses" => fn(HasMany $query) =>
        $query->select("expenses.*")
        ->join("sources", "sources.id", "expenses.source_id")
        ->whereRaw(<<<SQL
          date(case "next" when true then date("date" + interval '1 month') else "date" end + interval '1 month' * (instalments - 1)) >= date(date_trunc('month', '$start'::date)::date + coalesce(sources.cutoff, 0)) and
          case "next" when true then date("date" + interval '1 month') else "date" end <= date(date_trunc('month', '$start'::date)::date + interval '1 month') - 1 + coalesce(sources.cutoff, 0)
        SQL)
        ->whereNotNull("instalments")
        ->orderBy("date")
        ->orderBy("id")
    ])->orderBy("sources.id")
    ->get()
    ->toArray();

    foreach ($expenses as $index => $source) {
      $expenses[$index]["expenses_count"] = sizeof($source["expenses"]);
      $expenses[$index]["instalments_count"] = 0;
      $expenses[$index]["instalments"] = [];
      $tmp[$source["id"]] = $index;
    }

    foreach ($instalments as $source) {
      $index = $tmp[$source["id"]];
      $expenses[$index]["instalments"] = $source["expenses"];
      $expenses[$index]["instalments_count"] = sizeof($source["expenses"]);
    }

    $incomes = $user->sources()
    ->with([
      "incomes" => fn(HasMany $query) =>
        $query->whereBetween("date", [$start, $end])
        ->orderBy("date")
        ->orderBy("id")
    ])->withCount([
      "incomes" => fn(Builder $query) =>
        $query->whereBetween("date", [$start, $end])
    ])->orderBy("sources.id")
    ->get()
    ->toArray();

    foreach ($incomes as $source) {
      $index = $tmp[$source["id"]];
      $expenses[$index]["incomes"] = $source["incomes"];
      $expenses[$index]["incomes_count"] = $source["incomes_count"];
    }

    $source_ids = $user->sources->pluck("id");

    $categories = Category::orderBy("order")
    ->orderBy("name")
    ->withCount([
      "expenses" => fn(Builder $query) =>
        $query->whereIn("expenses.source_id", $source_ids)
        ->join("sources", "sources.id", "expenses.source_id")
        ->whereRaw(<<<SQL
          case expenses.next when true then date(expenses.date + interval '1 month') else expenses.date end between
            date(date_trunc('month', '$start'::date)::date + coalesce(sources.cutoff, 0)) and
            date(date_trunc('month', '$start'::date)::date + interval '1 month') - 1 + coalesce(sources.cutoff, 0)
        SQL)
        ->whereNull("instalments")
    ])
    ->withSum([
      "expenses" => fn(Builder $query) =>
        $query->whereIn("expenses.source_id", $source_ids)
        ->join("sources", "sources.id", "expenses.source_id")
        ->whereRaw(<<<SQL
          case expenses.next when true then date(expenses.date + interval '1 month') else expenses.date end between
            date(date_trunc('month', '$start'::date)::date + coalesce(sources.cutoff, 0)) and
            date(date_trunc('month', '$start'::date)::date + interval '1 month') - 1 + coalesce(sources.cutoff, 0)
        SQL)
        ->whereNull("instalments"),
    ], "amount")
    ->with([
      "expenses" => fn(HasMany $query) =>
        $query->whereIn("expenses.source_id", $source_ids)
        ->join("sources", "sources.id", "expenses.source_id")
        ->whereRaw(<<<SQL
          date(case expenses.next when true then date(expenses.date + interval '1 month') else expenses.date end + interval '1 month' * (expenses.instalments - 1)) >= date(date_trunc('month', '$start'::date)::date + coalesce(sources.cutoff, 0)) and
          case expenses.next when true then date(expenses.date + interval '1 month') else expenses.date end <= date(date_trunc('month', '$start'::date)::date + interval '1 month') - 1 + coalesce(sources.cutoff, 0)
        SQL)
        ->whereNotNull("instalments")
    ])
    ->get();

    foreach ($categories as $category) {
      $category->expenses_count += $category->expenses->count();
      $category->expenses_sum_amount += $category->expenses->sum(fn($expense) => $expense->amount / $expense->instalments);
      $category->makeHidden(["expenses"]);
    }

    return [
      "expenses" => $expenses,
      "categories" => $categories,
    ];
  }
}
