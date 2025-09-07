<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AppController extends Controller
{
  public function __invoke(Request $request) {
    $user = Auth::user();
    $date = isset($request->date) ? new Carbon($request->date) : Carbon::now();
    $date->day = 1;
    $start = $date->format("Y-m-d");
    $date->month += 1;
    $date->day -= 1;
    $end = $date->format("Y-m-d");
    $tmp = [];

    $expenses = $user->sources()
    ->with([
      "expenses" => fn(Builder $query) =>
        $query->select("expenses.*")
        ->join("sources", "sources.id", "expenses.source_id")
        ->whereRaw("date between date(date_trunc('month', '$start'::date)::date + coalesce(sources.cutoff, 0)) and date(date_trunc('month', '$start'::date)::date + interval '1 month') - 1 + coalesce(sources.cutoff, 0)")
        ->whereNull("instalments")
        ->orderBy("date")
        ->orderBy("expenses.id")
    ])->orderBy("sources.id")
    ->get()
    ->toArray();

    $instalments = $user->sources()->with([
      "expenses" => fn(Builder $query) =>
        $query->select("expenses.*")
        ->join("sources", "sources.id", "expenses.source_id")
        ->whereRaw("date(\"date\" + interval '1 month' * (\"instalments\" - 1)) >= date(date_trunc('month', '$start'::date)::date + coalesce(sources.cutoff, 0))")
        ->whereRaw("\"date\" <= date(date_trunc('month', '$start'::date)::date + interval '1 month') - 1 + coalesce(sources.cutoff, 0)")
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
      "incomes" => fn(Builder $query) =>
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

    $categories = Category::orderBy("order")
    ->orderBy("name")
    ->get();

    return [
      "expenses" => $expenses,
      "categories" => $categories,
    ];
  }
}
