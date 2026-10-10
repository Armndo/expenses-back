<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Income;
use App\Models\Transfer;
use App\Models\User;
use Carbon\Carbon;

/**
 * Money math of a user, computed from the billing periods of each source.
 *
 * A source's position up to a month is `incomes - billed expenses - transfers out + transfers in`,
 * counted from `expenses.balance_start`. The balance is the sum of the positions, "cash" the sum of the
 * accounts (sources without cutoff) and "debt" what the cards (sources with a cutoff) owe.
 */
class Summary
{
  /** @var array<string, array<int, float>|null> */
  private array $positions = [];

  public function __construct(private User $user) {}

  /**
   * Billing period of the month starting at `$start`, as SQL expressions over `sources`: from the
   * source cutoff to the day before the next one (a source without cutoff falls back to the
   * calendar month).
   */
  public static function period(string $start): array {
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
  public static function billedIn($query, string $start) {
    [$periodStart, $periodEnd] = self::period($start);
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

  /**
   * What an instalment bills in a month: `$amount / $instalments` rounded to cents, halves away from zero.
   * It works on whole cents because dividing floats gives results like 1200.4649999 for an exact
   * 1200.465, and the SQL in `spentBySource()`, the web and the app must all round the same way.
   */
  public static function share(float $amount, int $instalments): float {
    $cents = (int) round($amount * 100);
    $share = round(abs($cents) / $instalments);

    return ($cents < 0 ? -$share : $share) / 100;
  }

  /** First month counted by the running balance. */
  public function balanceStart(): Carbon {
    // The fallback covers a stale cached config (config:cache run before config/expenses.php existed),
    // where the key is missing and Carbon would silently parse null as "now".
    return Carbon::parse(config("expenses.balance_start") ?? "2026-09-01")->startOfMonth();
  }

  /**
   * Position of every source (id => amount) up to the month starting at `$start`, or `null` when the
   * month is before the balance start (or absurdly far after it).
   */
  public function positions(string $start): ?array {
    if (array_key_exists($start, $this->positions)) {
      return $this->positions[$start];
    }

    $month = Carbon::parse($start);
    $balanceStart = $this->balanceStart();

    if ($month->lt($balanceStart) || $balanceStart->diffInMonths($month) > 120) {
      return $this->positions[$start] = null;
    }

    $first = $balanceStart->format("Y-m-d");
    $positions = $this->user->sources()->pluck("id")->mapWithKeys(fn($id) => [$id => 0.0])->all();

    $add = function (array $amounts, int $sign) use (&$positions) {
      foreach ($amounts as $source_id => $amount) {
        $positions[$source_id] += $sign * (float) $amount;
      }
    };

    $add($this->incomeBySource($first, $start), 1);

    for ($m = $balanceStart->copy(); $m->lte($month); $m->addMonth()) {
      $add($this->spentBySource($m->format("Y-m-d")), -1);
    }

    $add($this->transfersOut($first, $start), -1);
    $add($this->transfersIn($first, $start), 1);

    // Cents only: drops the floating point noise of adding and subtracting many amounts.
    return $this->positions[$start] = array_map(fn($amount) => round($amount, 2), $positions);
  }

  /**
   * Totals of the month starting at `$start`:
   * - `spent`, `income`: the month's own (income by source cutoff, like expenses).
   * - `cash`, `debt`, `total_money`: from the positions (`total_money = cash - debt`, the running
   *   balance). All `null` before the balance start.
   */
  public function totals(string $start): array {
    $totals = [
      "spent" => array_sum($this->spentBySource($start)),
      "income" => array_sum($this->incomeBySource($start, $start)),
      "cash" => null,
      "debt" => null,
      "total_money" => null,
    ];

    if (($positions = $this->positions($start)) !== null) {
      $cards = $this->user->sources()->whereNotNull("cutoff")->pluck("id")->all();
      $totals["debt"] = -array_sum(array_intersect_key($positions, array_flip($cards)));
      $totals["cash"] = array_sum(array_diff_key($positions, array_flip($cards)));
      $totals["total_money"] = $totals["cash"] - $totals["debt"];
    }

    return $totals;
  }

  /**
   * What each source bills in the period starting at `$start`: cash expenses plus the monthly share of
   * instalments. Each share is rounded to cents, as the bank bills it, so paying a statement leaves no
   * stray cent (the web and the app round the same way).
   */
  private function spentBySource(string $start): array {
    $query = Expense::query()
    ->join("sources", "sources.id", "expenses.source_id")
    ->where("sources.user_id", $this->user->id);

    return self::billedIn($query, $start)
    ->selectRaw("expenses.source_id as source_id, coalesce(sum(case when expenses.instalments is null then expenses.amount::numeric else round(expenses.amount::numeric / expenses.instalments, 2) end), 0) as total")
    ->groupBy("expenses.source_id")
    ->pluck("total", "source_id")->all();
  }

  /** Incomes of each source from the period starting at `$first` to the one starting at `$last`, both included. */
  private function incomeBySource(string $first, string $last): array {
    return Income::query()
    ->join("sources", "sources.id", "incomes.source_id")
    ->where("sources.user_id", $this->user->id)
    ->whereRaw("incomes.date between " . self::period($first)[0] . " and " . self::period($last)[1])
    ->selectRaw("incomes.source_id as source_id, sum(incomes.amount) as total")
    ->groupBy("incomes.source_id")
    ->pluck("total", "source_id")->all();
  }

  /**
   * What leaves each source in transfers. Transfers count by the calendar month of their date on both
   * ends (the money moves that day), not by the cutoff of either source: otherwise a payment dated
   * between the two cutoffs would change the balance of that month.
   */
  private function transfersOut(string $first, string $last): array {
    return Transfer::query()
    ->join("sources", "sources.id", "transfers.from_source_id")
    ->where("sources.user_id", $this->user->id)
    ->whereRaw(self::calendarRange("transfers.date", $first, $last))
    ->selectRaw("transfers.from_source_id as source_id, sum(transfers.amount) as total")
    ->groupBy("transfers.from_source_id")
    ->pluck("total", "source_id")->all();
  }

  /** What arrives in each source in transfers (the received amount, or the sent one when there is none). */
  private function transfersIn(string $first, string $last): array {
    return Transfer::query()
    ->join("sources", "sources.id", "transfers.to_source_id")
    ->where("sources.user_id", $this->user->id)
    ->whereRaw(self::calendarRange("transfers.date", $first, $last))
    ->selectRaw("transfers.to_source_id as source_id, sum(coalesce(transfers.received_amount, transfers.amount)) as total")
    ->groupBy("transfers.to_source_id")
    ->pluck("total", "source_id")->all();
  }

  /** SQL for `$column` being in the calendar months from the one starting at `$first` to the one starting at `$last`. */
  public static function calendarRange(string $column, string $first, string $last): string {
    return "$column >= '$first'::date and $column < date('$last'::date + interval '1 month')";
  }
}
