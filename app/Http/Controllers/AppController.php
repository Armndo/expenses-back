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

    $sources = $user->sources()
    ->with([
      "expenses" => fn(HasMany $query) =>
        $query->select("expenses.*")
        ->join("sources", "sources.id", "expenses.source_id")
        ->where(function ($q) use ($start) {
          $q->where(function ($q) use ($start) {
            $q->whereRaw(<<<SQL
              case "next" when true then date("date" + interval '1 month') else "date" end between
                date(date_trunc('month', '$start'::date)::date + coalesce(sources.cutoff, 0)) and
                date(date_trunc('month', '$start'::date)::date + interval '1 month') - 1 + coalesce(sources.cutoff, 0)
            SQL)
            ->whereNull("instalments");
          })->orWhere(function ($q) use ($start) {
            $q->whereRaw(<<<SQL
              date(case "next" when true then date("date" + interval '1 month') else "date" end + interval '1 month' * (instalments - 1)) >= date(date_trunc('month', '$start'::date)::date + coalesce(sources.cutoff, 0)) and
              case "next" when true then date("date" + interval '1 month') else "date" end <= date(date_trunc('month', '$start'::date)::date + interval '1 month') - 1 + coalesce(sources.cutoff, 0)
            SQL)
            ->whereNotNull("instalments");
          });
        })
        ->orderByDesc("date")
        ->orderByDesc("expenses.id"),
      "incomes" => fn(HasMany $query) =>
        $query->whereBetween("date", [$start, $end])
        ->orderBy("date")
        ->orderBy("id"),
    ])
    ->withCount([
      "incomes" => fn(Builder $query) =>
        $query->whereBetween("date", [$start, $end])
    ])
    ->orderBy("sources.id")
    ->get();

    $source_ids = $sources->pluck("id");

    $expenses = $sources->map(function ($source) {
      [$instalments, $regular] = $source->expenses->partition(fn($e) => !is_null($e->instalments));
      $arr = $source->toArray();
      $arr["expenses"] = $regular->values()->toArray();
      $arr["expenses_count"] = $regular->count();
      $arr["instalments"] = $instalments->values()->toArray();
      $arr["instalments_count"] = $instalments->count();

      return $arr;
    })->toArray();

    $categories = Category::orderBy("order")
    ->orderBy("name")
    ->with([
      "expenses" => fn(HasMany $query) =>
        $query->select("expenses.*")
        ->whereIn("expenses.source_id", $source_ids)
        ->join("sources", "sources.id", "expenses.source_id")
        ->where(function ($q) use ($start) {
          $q->where(function ($q) use ($start) {
            $q->whereRaw(<<<SQL
              case expenses.next when true then date(expenses.date + interval '1 month') else expenses.date end between
                date(date_trunc('month', '$start'::date)::date + coalesce(sources.cutoff, 0)) and
                date(date_trunc('month', '$start'::date)::date + interval '1 month') - 1 + coalesce(sources.cutoff, 0)
            SQL)
            ->whereNull("instalments");
          })->orWhere(function ($q) use ($start) {
            $q->whereRaw(<<<SQL
              date(case expenses.next when true then date(expenses.date + interval '1 month') else expenses.date end + interval '1 month' * (expenses.instalments - 1)) >= date(date_trunc('month', '$start'::date)::date + coalesce(sources.cutoff, 0)) and
              case expenses.next when true then date(expenses.date + interval '1 month') else expenses.date end <= date(date_trunc('month', '$start'::date)::date + interval '1 month') - 1 + coalesce(sources.cutoff, 0)
            SQL)
            ->whereNotNull("instalments");
          });
        })
    ])
    ->get();

    foreach ($categories as $category) {
      [$instalments, $regular] = $category->expenses->partition(fn($e) => !is_null($e->instalments));
      $category->expenses_count = $regular->count() + $instalments->count();
      $category->expenses_sum_amount = $regular->sum("amount") + $instalments->sum(fn($e) => $e->amount / $e->instalments);
      $category->makeHidden(["expenses"]);
    }

    return [
      "expenses" => $expenses,
      "categories" => $categories,
    ];
  }
}
