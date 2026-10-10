<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Summary;
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

    [$periodStart, $periodEnd] = Summary::period($start);

    $sources = $user->sources()
    ->with([
      "expenses" => fn(HasMany $query) =>
        $query->select("expenses.*")
        ->join("sources", "sources.id", "expenses.source_id")
        ->tap(fn($q) => Summary::billedIn($q, $start))
        ->orderByDesc("expenses.date")
        ->orderByDesc("expenses.id"),
      "incomes" => fn(HasMany $query) =>
        $query->select("incomes.*")
        ->join("sources", "sources.id", "incomes.source_id")
        ->whereRaw("incomes.date between $periodStart and $periodEnd")
        ->orderByDesc("incomes.date")
        ->orderByDesc("incomes.id"),
      "outgoingTransfers" => fn(HasMany $query) =>
        $query->select("transfers.*")
        ->whereRaw(Summary::calendarRange("transfers.date", $start, $start))
        ->with("to:id,name"),
      "incomingTransfers" => fn(HasMany $query) =>
        $query->select("transfers.*")
        ->whereRaw(Summary::calendarRange("transfers.date", $start, $start))
        ->with("from:id,name"),
    ])
    ->withCount([
      "incomes" => fn(Builder $query) =>
        // Correlated subquery: `sources` is the outer table, no join needed.
        $query->whereRaw("incomes.date between $periodStart and $periodEnd")
    ])
    ->orderBy("sources.id")
    ->get();

    $source_ids = $sources->pluck("id");

    $summary = new Summary($user);
    $positions = $summary->positions($start);

    $expenses = $sources->map(function ($source) use ($positions) {
      [$instalments, $regular] = $source->expenses->partition(fn($e) => !is_null($e->instalments));
      $transfers = $source->outgoingTransfers->map(fn($t) => $this->transferItem($t, "out"))
        ->concat($source->incomingTransfers->map(fn($t) => $this->transferItem($t, "in")))
        ->sortBy([["date", "desc"], ["id", "desc"]])->values();
      $arr = $source->toArray();
      unset($arr["outgoing_transfers"], $arr["incoming_transfers"]);
      $arr["expenses"] = $regular->values()->toArray();
      $arr["expenses_count"] = $regular->count();
      $arr["instalments"] = $instalments->values()->toArray();
      $arr["instalments_count"] = $instalments->count();
      $arr["transfers"] = $transfers->all();
      $arr["transfers_count"] = $transfers->count();
      $arr["balance"] = $positions[$source->id] ?? null;

      return $arr;
    })->toArray();

    $categories = Category::orderBy("order")
    ->orderBy("name")
    ->with([
      "expenses" => fn(HasMany $query) =>
        $query->select("expenses.*")
        ->whereIn("expenses.source_id", $source_ids)
        ->join("sources", "sources.id", "expenses.source_id")
        ->tap(fn($q) => Summary::billedIn($q, $start))
    ])
    ->get();

    foreach ($categories as $category) {
      [$instalments, $regular] = $category->expenses->partition(fn($e) => !is_null($e->instalments));
      $category->expenses_count = $regular->count() + $instalments->count();
      $category->expenses_sum_amount = $regular->sum("amount") + $instalments->sum(fn($e) => Summary::share($e->amount, $e->instalments));
      $category->makeHidden(["expenses"]);
    }

    return [
      "expenses" => $expenses,
      "categories" => $categories,
      "summary" => $summary->totals($start),
    ];
  }

  /** A transfer as seen from one of its two sources: signed amount in that source's currency and the other end. */
  private function transferItem(Transfer $transfer, string $direction): array {
    $out = $direction === "out";
    $counterpart = $out ? $transfer->to : $transfer->from;
    $item = $transfer->toArray();
    unset($item["to"], $item["from"]);

    return $item + [
      "direction" => $direction,
      "signed_amount" => $out ? -$transfer->amount : ($transfer->received_amount ?? $transfer->amount),
      "counterpart_id" => $counterpart->id,
      "counterpart_name" => $counterpart->name,
    ];
  }
}
