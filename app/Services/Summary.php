<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Income;
use App\Models\Transfer;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Money math of a user, computed from the billing periods of each source.
 *
 * A source's position up to a month is `incomes - billed expenses - transfers out + transfers in`,
 * counted from `expenses.balance_start`. The balance is the sum of the positions, "cash" the sum of the
 * accounts (sources without cutoff) and "debt" what the cards (sources with a cutoff) owe. The balance
 * ("total_money") also subtracts the instalments still to be billed, so a purchase weighs whole from its
 * first period.
 *
 * Every source keeps its own currency; the totals are converted to pesos with the user's exchange rates
 * (the current rate, for all the history).
 */
class Summary
{
  /** @var array<string, array<int, float>|null> */
  private array $positions = [];

  /** @var array<string, array<int, float>> month start => spent by source, so each month is queried once */
  private array $spent = [];

  /** @var array<string, array<int, float>> month start => budget incomes by source, so each month is queried once */
  private array $budgetIncomes = [];

  /** @var array<string, float>|null */
  private ?array $rates = null;

  /** @var array<int, string>|null */
  private ?array $currencies = null;

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

  /**
   * First month counted by the running balance: the user's setting, or the config when they have none.
   */
  public function balanceStart(): Carbon {
    // The fallback covers a stale cached config (config:cache run before config/expenses.php existed),
    // where the key is missing and Carbon would silently parse null as "now".
    return Carbon::parse($this->user->setting?->balance_start ?? config("expenses.balance_start") ?? "2026-09-01")->startOfMonth();
  }

  /** Pesos the user wants to spend a month, or null when they set no budget. */
  public function budget(): ?float {
    return $this->user->setting?->monthly_budget;
  }

  /** First month the budget counts from: the user's setting, or the balance start when they have none. */
  public function budgetStart(): Carbon {
    $start = $this->user->setting?->budget_start;

    return $start ? Carbon::parse($start)->startOfMonth() : $this->balanceStart();
  }

  /**
   * What the budget leaves for the month starting at `$start`, as `[carry, left]`, or `null` without a budget
   * or before the budget start. A month's spent is net of its incomes that are not `skip_budget` (a refund
   * or a shared payment gives back what it cost; a salary or an opening balance is flagged and does not count).
   * The carry is the sum of `budget - net spent` of every month since the start up to the previous one
   * (negative is debt, positive is what was saved, and both pass to the next month), and
   * `left = budget + carry - net spent`. Also `null` while a currency has no rate, because `spent` is incomplete.
   */
  private function budgetLeft(string $start): ?array {
    $budget = $this->budget();
    $month = Carbon::parse($start);
    $budgetStart = $this->budgetStart();

    if ($budget === null || $month->lt($budgetStart) || $budgetStart->diffInMonths($month) > 120 || $this->missingRates()) {
      return null;
    }

    $carry = 0.0;

    for ($m = $budgetStart->copy(); $m->lt($month); $m->addMonth()) {
      $carry += $budget - $this->netSpent($m->format("Y-m-d"));
    }

    $carry = round($carry, 2);

    return [$carry, round($budget + $carry - $this->netSpent($start), 2)];
  }

  /** The spent of the period starting at `$start`, in pesos, minus the incomes that count for the budget. */
  private function netSpent(string $start): float {
    return $this->toBase($this->spentBySource($start)) - $this->toBase($this->budgetIncomeBySource($start));
  }

  /** Currency every amount is converted to (its rate is always 1). */
  public static function baseCurrency(): string {
    return config("expenses.currencies", ["MXN", "USD"])[0];
  }

  /** Pesos per unit of each currency the user has a rate for (the base one included). */
  public function rates(): array {
    return $this->rates ??= [self::baseCurrency() => 1.0] + $this->user->exchangeRates()->pluck("rate", "currency")->map(fn($rate) => (float) $rate)->all();
  }

  /** Currencies of the user's sources that have no exchange rate, so their amounts cannot be converted. */
  public function missingRates(): array {
    return array_values(array_unique(array_filter(
      $this->sourceCurrencies(),
      fn($currency) => !array_key_exists($currency, $this->rates()),
    )));
  }

  /** @return array<int, string> source id => currency */
  private function sourceCurrencies(): array {
    return $this->currencies ??= $this->user->sources()->pluck("currency", "id")->all();
  }

  /**
   * Adds up amounts by source (id => amount in the source's currency) in pesos. A source whose currency
   * has no rate adds nothing: `missingRates()` says that it happened.
   */
  private function toBase(array $amounts): float {
    $total = 0.0;

    foreach ($amounts as $source_id => $amount) {
      $rate = $this->rates()[$this->sourceCurrencies()[$source_id] ?? self::baseCurrency()] ?? null;

      if ($rate !== null) {
        $total += (float) $amount * $rate;
      }
    }

    return round($total, 2);
  }

  /**
   * Converts one amount of a source to pesos with the source's rate, or null when it has none.
   */
  public function convert(float $amount, int $source_id): ?float {
    $rate = $this->rates()[$this->sourceCurrencies()[$source_id] ?? self::baseCurrency()] ?? null;

    return $rate === null ? null : $amount * $rate;
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
   * Totals of the month starting at `$start`, in pesos:
   * - `spent`, `income`: the month's own (income by source cutoff, like expenses).
   * - `cash`, `debt`: from the positions. `pending_instalments`: what the instalments already started
   *   will still bill. `total_money = cash - debt - pending_instalments`, the running balance with
   *   every instalment purchase counted whole from its first period. All `null` before the balance
   *   start, and also when a source's currency has no rate.
   * - `budget`, `budget_carry`, `budget_left`: the monthly budget, what the previous months left over or
   *     owe (the carry, negative is debt) and what is left of the month, `budget + carry - spent` (negative
   *     when exceeded). All `null` without a budget and before the budget start month (the balance start
   *     unless the user sets another one); the carry and what is left also when a currency has no rate,
   *     because `spent` is then incomplete.
   * - `missing_rates`: currencies in use without an exchange rate (their sources add nothing to `spent`
   *   and `income`).
   */
  public function totals(string $start): array {
    $totals = [
      "spent" => $this->toBase($this->spentBySource($start)),
      "income" => $this->toBase($this->incomeBySource($start, $start)),
      "cash" => null,
      "debt" => null,
      "pending_instalments" => null,
      "total_money" => null,
      "budget" => Carbon::parse($start)->gte($this->budgetStart()) ? $this->budget() : null,
      "budget_carry" => null,
      "budget_left" => null,
      "missing_rates" => $this->missingRates(),
    ];

    if ($totals["budget"] !== null && ($budget = $this->budgetLeft($start)) !== null) {
      [$totals["budget_carry"], $totals["budget_left"]] = $budget;
    }

    if (($positions = $this->positions($start)) !== null && !$totals["missing_rates"]) {
      $cards = $this->user->sources()->whereNotNull("cutoff")->pluck("id")->all();
      $totals["debt"] = -$this->toBase(array_intersect_key($positions, array_flip($cards)));
      $totals["cash"] = $this->toBase(array_diff_key($positions, array_flip($cards)));
      $totals["pending_instalments"] = $this->toBase($this->pendingBySource($start));
      $totals["total_money"] = round($totals["cash"] - $totals["debt"] - $totals["pending_instalments"], 2);
    }

    return $totals;
  }

  /**
   * The first and the last month (`Y-m-d` of its first day) the user can look at, as `["first" => ..., "last" => ...]`.
   * The first is the month of the oldest expense, or the balance start (and the budget start, when there is a budget)
   * if that is earlier; the last is the month the last expense is billed in, counting the instalments that are still
   * to come (an expense in 12 instalments reaches 11 months past its first period), and never before the current
   * month. Months are those of the billing periods of each source, like `billedIn()`. With no expenses it is the
   * current month for both.
   */
  public function range(): array {
    $effective = "case expenses.next when true then date(expenses.date + interval '1 month') else expenses.date end";
    $label = "date_trunc('month', ($effective) - coalesce(sources.cutoff, 0) * interval '1 day')";

    $row = Expense::query()
    ->join("sources", "sources.id", "expenses.source_id")
    ->where("sources.user_id", $this->user->id)
    ->selectRaw("min($label) as first, max($label + (coalesce(expenses.instalments, 1) - 1) * interval '1 month') as last")
    ->first();

    $now = Carbon::now()->startOfMonth();

    $firsts = array_filter([
      $row?->first ? Carbon::parse($row->first)->startOfMonth() : null,
      $this->balanceStart(),
      $this->budget() !== null ? $this->budgetStart() : null,
    ]);

    $first = collect($firsts)->push($now)->min();
    $last = collect([$row?->last ? Carbon::parse($row->last)->startOfMonth() : null, $now])->filter()->max();

    return ["first" => $first->format("Y-m-d"), "last" => $last->format("Y-m-d")];
  }

  /**
   * The totals of every month of the `range()`, oldest first (each is `totals()` plus its `month`). The months after
   * the current one are a projection: what the expenses already registered (the instalments to come and the `next`
   * ones) still bill, with no new income or spending. The budget is not projected (`budget`, `budget_carry` and
   * `budget_left` are `null` after the current month): with no spending it would only pile up. `spent` covers every
   * month with expenses while the rest only has values from the balance start on, as in `totals()`. At most 120 months, the last ones; none when the range
   * starts after it ends.
   */
  public function history(): array {
    $range = $this->range();
    $from = Carbon::parse($range["first"]);
    $to = Carbon::parse($range["last"]);

    if ($from->gt($to)) {
      return [];
    }

    if ($from->diffInMonths($to) > 119) {
      $from = $to->copy()->subMonths(119);
    }

    $months = [];

    $now = Carbon::now()->startOfMonth();

    for ($m = $from->copy(); $m->lte($to); $m->addMonth()) {
      $totals = $this->totals($m->format("Y-m-d"));

      if ($m->gt($now)) {
        $totals["budget"] = $totals["budget_carry"] = $totals["budget_left"] = null;
      }

      $months[] = ["month" => $m->format("Y-m-d")] + $totals;
    }

    return $months;
  }

  /**
   * What the instalments already started by the period starting at `$start` will still bill after it
   * (id => amount in the source's currency): each one's rounded share times the periods left. The
   * balance subtracts it up front, so a purchase in instalments weighs all at once from its first
   * period instead of month by month. The positions of the sources do not include it: a card paid
   * with its statement still ends at zero.
   */
  private function pendingBySource(string $start): array {
    $effective = "case expenses.next when true then date(expenses.date + interval '1 month') else expenses.date end";
    $periodEnd = self::period($start)[1];
    $laterStart = "date(date_trunc('month', '$start'::date + interval '1 month' * later.n)::date + coalesce(sources.cutoff, 0))";

    return Expense::query()
    ->join("sources", "sources.id", "expenses.source_id")
    ->where("sources.user_id", $this->user->id)
    ->whereNotNull("expenses.instalments")
    ->crossJoin(DB::raw("generate_series(1, expenses.instalments - 1) as later(n)"))
    ->whereRaw("$effective <= $periodEnd")
    ->whereRaw("$laterStart <= date($effective + interval '1 month' * (expenses.instalments - 1))")
    ->selectRaw("expenses.source_id as source_id, sum(round(expenses.amount::numeric / expenses.instalments, 2)) as total")
    ->groupBy("expenses.source_id")
    ->pluck("total", "source_id")->all();
  }

  /**
   * What each source bills in the period starting at `$start`: cash expenses plus the monthly share of
   * instalments. Each share is rounded to cents, as the bank bills it, so paying a statement leaves no
   * stray cent (the web and the app round the same way).
   */
  private function spentBySource(string $start): array {
    if (array_key_exists($start, $this->spent)) {
      return $this->spent[$start];
    }

    $query = Expense::query()
    ->join("sources", "sources.id", "expenses.source_id")
    ->where("sources.user_id", $this->user->id);

    return $this->spent[$start] = self::billedIn($query, $start)
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

  /** Incomes of each source in the period starting at `$start` that count for the budget (not `skip_budget`). */
  private function budgetIncomeBySource(string $start): array {
    if (array_key_exists($start, $this->budgetIncomes)) {
      return $this->budgetIncomes[$start];
    }

    return $this->budgetIncomes[$start] = Income::query()
    ->join("sources", "sources.id", "incomes.source_id")
    ->where("sources.user_id", $this->user->id)
    ->where("incomes.skip_budget", false)
    ->whereRaw("incomes.date between " . self::period($start)[0] . " and " . self::period($start)[1])
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
