<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Summary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * `GET /search?q=&type=&category_id=&min=&max=&from=&to=&page=`: the expenses, incomes and transfers of the user across all the
 * months, one row per record (an expense in instalments comes once, with its total, its monthly share and the first and last
 * billing months, not once per month), newest billing month first, 50 a page. Read only; anything that is not valid is `400 "error"`.
 *
 * - `q`: every word must be in the description, the category (name or alias), the source (and the other account of a transfer) or the
 *   amount, whatever the case or the accents.
 * - `type`: a comma separated list of `expenses` (without instalments), `instalments`, `incomes` and `transfers` (all by default).
 * - `category_id`: only expenses (incomes and transfers have no category).
 * - `min`, `max`: pesos, converted with the user's rates, only for expenses (instalments included), against the amount of the purchase;
 *   an expense in a currency with no rate cannot be compared and stays.
 * - `from`, `to`: `YYYY-MM-DD`, against the date of the record.
 *
 * Each row has `type` (`expense`, `income`, `transfer`), `id`, `date`, `description`, `amount`, `received_amount`, `currency`,
 * `category_id`, `category_name`, `category_alias`, `source_id`, `source_name` (the origin of a transfer) and `to_source_id`,
 * `to_source_name` (transfers), `instalments`, `monthly_share`, `next`, `period` (`YYYY-MM`, where it is first billed) and `period_last`.
 */
class SearchController extends Controller
{
  const PER_PAGE = 50;

  const TYPES = ["expenses", "instalments", "incomes", "transfers"];

  /** Lowercase text without the accents of Spanish, to compare with what is typed whatever the case or the accents. */
  const FOLD = "translate(lower(%s), 'áéíóúüñÁÉÍÓÚÜÑ', 'aeiouunaeiouun')";

  public function __invoke(Request $request) {
    $user = User::find(Auth::user()->id);
    $filters = $this->filters($request);

    if ($filters === null) {
      return response()->json("error", 400);
    }

    [$records, $bindings] = $this->records($user);
    [$where, $whereBindings] = $this->conditions($filters, $user);
    $from = "from ($records) as records $where";

    $total = (int) DB::selectOne("select count(*) as total $from", [...$bindings, ...$whereBindings])->total;
    $offset = ($filters["page"] - 1) * self::PER_PAGE;
    $rows = DB::select(
      "select * $from order by period desc, date desc, type, id desc limit ? offset ?",
      [...$bindings, ...$whereBindings, self::PER_PAGE, $offset],
    );

    return [
      "data" => array_map(fn($row) => $this->row($row), $rows),
      "page" => $filters["page"],
      "per_page" => self::PER_PAGE,
      "total" => $total,
      "last_page" => max(1, (int) ceil($total / self::PER_PAGE)),
    ];
  }

  /** The filters of the request, or `null` when one of them is not valid. */
  private function filters(Request $request): ?array {
    $query = $request->query();
    $text = $query["q"] ?? "";
    $page = $query["page"] ?? 1;
    $types = self::TYPES;

    if (!is_string($text) || mb_strlen($text) > 100 || !$this->isInteger($page, 1)) {
      return null;
    }

    if (isset($query["type"]) && $query["type"] !== "") {
      $types = is_string($query["type"]) ? array_values(array_unique(explode(",", $query["type"]))) : [];

      if ($types === [] || array_diff($types, self::TYPES) !== []) {
        return null;
      }
    }

    $category = $query["category_id"] ?? null;

    if ($category !== null && $category !== "" && !$this->isInteger($category, 1)) {
      return null;
    }

    foreach (["min", "max"] as $key) {
      if (isset($query[$key]) && $query[$key] !== "" && !is_numeric($query[$key])) {
        return null;
      }
    }

    foreach (["from", "to"] as $key) {
      if (isset($query[$key]) && $query[$key] !== "" && !$this->validDate($query[$key])) {
        return null;
      }
    }

    $number = fn($key) => isset($query[$key]) && $query[$key] !== "" ? (float) $query[$key] : null;
    $date = fn($key) => isset($query[$key]) && $query[$key] !== "" ? $query[$key] : null;

    return [
      "needles" => array_values(array_filter(preg_split('/\s+/', $this->fold($text)) ?: [])),
      "types" => $types,
      "category_id" => $category === null || $category === "" ? null : (int) $category,
      "min" => $number("min"),
      "max" => $number("max"),
      "from" => $date("from"),
      "to" => $date("to"),
      "page" => (int) $page,
    ];
  }

  /**
   * One row for every expense, income and transfer of the user, with the same columns, the billing months and the text the search
   * looks into (`haystack`), as a `union all` and its bindings. The billing months follow `billedIn()` and `Summary::expensePeriods()`:
   * the effective date (a month after with `next`, without overflowing) minus the cutoff days, and an instalment of N periods ends in the
   * one that holds the effective date plus N - 1 months.
   */
  private function records(User $user): array {
    $rates = (new Summary($user))->rates();
    $pesos = "case s.currency " . implode(" ", array_fill(0, count($rates), "when ? then cast(? as numeric)")) . " end";
    $rateBindings = [];

    foreach ($rates as $currency => $rate) {
      array_push($rateBindings, $currency, $rate);
    }

    $effective = "(case when e.next then e.date + interval '1 month' else e.date::timestamp end)";
    $month = fn($date, $cutoff) => "to_char(date_trunc('month', ($date)::date - coalesce($cutoff, 0)), 'YYYY-MM')";
    $text = fn(...$parts) => sprintf(self::FOLD, "concat_ws(' ', " . implode(", ", $parts) . ")");

    $expenses = "select 'expense' as type, e.id::bigint as id, e.date as date, e.description::text as description, e.amount::numeric as amount,
        null::numeric as received_amount, s.currency::text as currency, e.category_id::bigint as category_id, c.name::text as category_name,
        c.alias::text as category_alias, s.id::bigint as source_id, s.name::text as source_name, null::bigint as to_source_id,
        null::text as to_source_name, e.instalments::int as instalments, e.next as next,
        {$month($effective, 's.cutoff')} as period,
        case when e.instalments is null then null else {$month("$effective + interval '1 month' * (e.instalments - 1)", 's.cutoff')} end as period_last,
        $pesos as pesos,
        {$text('e.description', 'c.name', 'c.alias', 's.name', 'e.amount::text', "to_char(e.amount, 'FM999999990.00')")} as haystack
      from expenses e join sources s on s.id = e.source_id left join categories c on c.id = e.category_id
      where s.user_id = ?";

    $incomes = "select 'income', i.id::bigint, i.date, i.description::text, i.amount::numeric, null::numeric, s.currency::text, null::bigint,
        null::text, null::text, s.id::bigint, s.name::text, null::bigint, null::text, null::int, false,
        {$month('i.date', 's.cutoff')}, null::text, null::numeric,
        {$text('i.description', 's.name', 'i.amount::text', "to_char(i.amount, 'FM999999990.00')")}
      from incomes i join sources s on s.id = i.source_id
      where s.user_id = ?";

    $transfers = "select 'transfer', t.id::bigint, t.date, t.description::text, t.amount::numeric, t.received_amount::numeric, f.currency::text, null::bigint,
        null::text, null::text, f.id::bigint, f.name::text, d.id::bigint, d.name::text, null::int, false,
        to_char(t.date, 'YYYY-MM'), null::text, null::numeric,
        {$text('t.description', 'f.name', 'd.name', 't.amount::text', "to_char(t.amount, 'FM999999990.00')")}
      from transfers t join sources f on f.id = t.from_source_id join sources d on d.id = t.to_source_id
      where f.user_id = ? and d.user_id = ?";

    return ["$expenses union all $incomes union all $transfers", [...$rateBindings, $user->id, $user->id, $user->id, $user->id]];
  }

  /** The `where` of the filters over the records, and its bindings. */
  private function conditions(array $filters, User $user): array {
    $where = [];
    $bindings = [];

    $kinds = [
      "expenses" => "(type = 'expense' and instalments is null)",
      "instalments" => "(type = 'expense' and instalments is not null)",
      "incomes" => "type = 'income'",
      "transfers" => "type = 'transfer'",
    ];

    if (count($filters["types"]) < count(self::TYPES)) {
      $where[] = "(" . implode(" or ", array_map(fn($type) => $kinds[$type], $filters["types"])) . ")";
    }

    if ($filters["category_id"] !== null) {
      $where[] = "(type = 'expense' and category_id = ?)";
      $bindings[] = $filters["category_id"];
    }

    foreach (["min" => ">=", "max" => "<="] as $key => $operator) {
      if ($filters[$key] !== null) {
        $where[] = "(type <> 'expense' or pesos is null or amount * pesos $operator ?)";
        $bindings[] = $filters[$key];
      }
    }

    foreach (["from" => ">=", "to" => "<="] as $key => $operator) {
      if ($filters[$key] !== null) {
        $where[] = "date $operator ?";
        $bindings[] = $filters[$key];
      }
    }

    foreach ($filters["needles"] as $needle) {
      $where[] = "haystack like ?";
      $bindings[] = "%" . addcslashes($needle, "\\%_") . "%";
    }

    return [$where === [] ? "" : "where " . implode(" and ", $where), $bindings];
  }

  /** A row of the query as the API shows it. */
  private function row(object $row): array {
    $amount = (float) $row->amount;

    return [
      "type" => $row->type,
      "id" => (int) $row->id,
      "date" => $row->date,
      "description" => $row->description,
      "amount" => $amount,
      "received_amount" => $row->received_amount === null ? null : (float) $row->received_amount,
      "currency" => $row->currency,
      "category_id" => $row->category_id === null ? null : (int) $row->category_id,
      "category_name" => $row->category_name,
      "category_alias" => $row->category_alias,
      "source_id" => (int) $row->source_id,
      "source_name" => $row->source_name,
      "to_source_id" => $row->to_source_id === null ? null : (int) $row->to_source_id,
      "to_source_name" => $row->to_source_name,
      "instalments" => $row->instalments === null ? null : (int) $row->instalments,
      "monthly_share" => $row->instalments === null ? null : Summary::share($amount, (int) $row->instalments),
      "next" => (bool) $row->next,
      "period" => $row->period,
      "period_last" => $row->period_last,
    ];
  }

  /** The same folding as `FOLD` in SQL (not `Str::ascii`, which would erase an emoji alias and leave nothing to search). */
  private function fold(string $text): string {
    return strtr(mb_strtolower(trim($text)), ["á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u", "ü" => "u", "ñ" => "n"]);
  }

  /** A whole number written as digits, at least `$min`. */
  private function isInteger(mixed $value, int $min): bool {
    return (is_string($value) || is_int($value)) && filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value >= $min;
  }

  /** A real date written as `YYYY-MM-DD`. */
  private function validDate(mixed $date): bool {
    return is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $p) === 1 && checkdate((int) $p[2], (int) $p[3], (int) $p[1]);
  }
}
