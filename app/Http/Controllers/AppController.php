<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Expense;
use App\Models\Income;
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

    [$periodStart, $periodEnd] = $this->period($start);

    $sources = $user->sources()
    ->with([
      "expenses" => fn(HasMany $query) =>
        $query->select("expenses.*")
        ->join("sources", "sources.id", "expenses.source_id")
        ->tap(fn($q) => $this->billedIn($q, $start))
        ->orderByDesc("expenses.date")
        ->orderByDesc("expenses.id"),
      "incomes" => fn(HasMany $query) =>
        $query->select("incomes.*")
        ->join("sources", "sources.id", "incomes.source_id")
        ->whereRaw("incomes.date between $periodStart and $periodEnd")
        ->orderByDesc("incomes.date")
        ->orderByDesc("incomes.id"),
    ])
    ->withCount([
      "incomes" => fn(Builder $query) =>
        // Correlated subquery: `sources` is the outer table, no join needed.
        $query->whereRaw("incomes.date between $periodStart and $periodEnd")
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
        ->tap(fn($q) => $this->billedIn($q, $start))
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
      "summary" => $this->summary($user, $start),
    ];
  }

  /**
   * Billing period of the month starting at `$start`, as SQL expressions over `sources`: from the
   * source cutoff to the day before the next one (a source without cutoff falls back to the
   * calendar month).
   */
  private function period(string $start): array {
    return [
      "date(date_trunc('month', '$start'::date)::date + coalesce(sources.cutoff, 0))",
      "date(date_trunc('month', '$start'::date)::date + interval '1 month') - 1 + coalesce(sources.cutoff, 0)",
    ];
  }

  /**
   * Restricts an expenses query (already joined with `sources`) to the expenses billed in the period
   * starting at `$start`: regular ones by their effective date, instalments while any of their N
   * periods overlaps it. The effective date is `date`, or `date + 1 month` when `next` is true.
   */
  private function billedIn($query, string $start) {
    [$periodStart, $periodEnd] = $this->period($start);
    $effective = "case expenses.next when true then date(expenses.date + interval '1 month') else expenses.date end";

    return $query->where(function ($q) use ($periodStart, $periodEnd, $effective) {
      $q->where(function ($q) use ($periodStart, $periodEnd, $effective) {
        $q->whereRaw("$effective between $periodStart and $periodEnd")
        ->whereNull("expenses.instalments");
      })->orWhere(function ($q) use ($periodStart, $periodEnd, $effective) {
        $q->whereRaw("date($effective + interval '1 month' * (expenses.instalments - 1)) >= $periodStart and $effective <= $periodEnd")
        ->whereNotNull("expenses.instalments");
      });
    });
  }

  /** What the user spends in the period starting at `$start`: cash expenses plus the monthly share of instalments. */
  private function spent(User $user, string $start): float {
    $query = Expense::query()
    ->join("sources", "sources.id", "expenses.source_id")
    ->where("sources.user_id", $user->id);

    return (float) $this->billedIn($query, $start)
    ->selectRaw("coalesce(sum(case when expenses.instalments is null then expenses.amount else expenses.amount / expenses.instalments end), 0) as total")
    ->value("total");
  }

  /** Incomes of the periods from the month starting at `$first` to the one starting at `$last`, both included. */
  private function income(User $user, string $first, string $last): float {
    return (float) Income::query()
    ->join("sources", "sources.id", "incomes.source_id")
    ->where("sources.user_id", $user->id)
    ->whereRaw("incomes.date between {$this->period($first)[0]} and {$this->period($last)[1]}")
    ->sum("incomes.amount");
  }

  /**
   * Totals of the month starting at `$start`:
   * - `spent`, `income`: the month's own (income by source cutoff, like expenses).
   * - `total_money`: running balance, the income from `expenses.balance_start` up to this month minus
   *   the spent from the start up to this month, both included (each month's balance is the previous
   *   one plus its income minus its spent). `null` before the start month (or absurdly far after it).
   */
  private function summary(User $user, string $start): array {
    $month = Carbon::parse($start);
    // The fallback covers a stale cached config (config:cache run before config/expenses.php existed),
    // where the key is missing and Carbon would silently parse null as "now".
    $balanceStart = Carbon::parse(config("expenses.balance_start") ?? "2026-09-01")->startOfMonth();

    $summary = [
      "spent" => $this->spent($user, $start),
      "income" => $this->income($user, $start, $start),
      "total_money" => null,
    ];

    if ($month->gte($balanceStart) && $balanceStart->diffInMonths($month) <= 120) {
      $spent = 0.0;

      for ($m = $balanceStart->copy(); $m->lte($month); $m->addMonth()) {
        $spent += $this->spent($user, $m->format("Y-m-d"));
      }

      $summary["total_money"] = $this->income($user, $balanceStart->format("Y-m-d"), $start) - $spent;
    }

    return $summary;
  }
}
