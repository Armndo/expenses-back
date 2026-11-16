<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Source;
use App\Services\Summary;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ExpenseController extends Controller
{
  public function store(Request $request) {
    $user = User::find(Auth::user()->id);
    $source = $user->sources()
    ->where("id", $request->source_id)
    ->first();

    // A disabled source takes no new movements, and the category must be the user's and not disabled.
    if (!$source || $source->disabled || !$this->categoryAllowed($user, $request->category_id, null) || !$this->recurrenceAllowed($request, null)) {
      return response()->json("error", 400);
    }

    try {
      $expense = $source->expenses()
      ->create($request->only("date", "next", "amount", "description", "category_id", "instalments", "recurs_every", "ends_on"));
    } catch (Exception $e) {
      return response()->json("error", 400);
    }

    return $this->withPeriod($expense, $source);
  }

  public function update(Request $request, $expense_id) {
    $user = User::find(Auth::user()->id);
    $expense = Expense::find($expense_id);

    if (!$expense) {
      return response()->json("error", 400);
    }

    $source = $user->sources()
    ->where("id", $expense->source_id)
    ->first();

    if (!$source) {
      return response()->json("error", 400);
    }

    // A recurring expense edited from one of its charges: `scope=following` cuts the rule there, no scope or `scope=all` edits the whole rule.
    // Any other scope is refused, not read as the whole rule, so a client that asks for something this API cannot do changes nothing. On
    // anything that is not a rule the scope means nothing and is ignored.
    if ($expense->recurs_every !== null) {
      $scope = $request->query("scope");

      if ($scope === "following") {
        return $this->cut($request, $user, $expense);
      }

      if ($scope !== null && $scope !== "all") {
        return response()->json("error", 400);
      }
    }

    if (!$this->targetAllowed($request, $user, $expense)) {
      return response()->json("error", 400);
    }

    // Changing the category: one of the user's that is not disabled (editing the amount of an old expense is always fine).
    if ($request->has("category_id") && !$this->categoryAllowed($user, $request->category_id, $expense->category_id)) {
      return response()->json("error", 400);
    }

    if (!$this->recurrenceAllowed($request, $expense)) {
      return response()->json("error", 400);
    }

    try {
      $expense->fill($request->only("date", "next", "amount", "description", "instalments", "recurs_every", "ends_on", "category_id", "source_id"));
      $expense->save();
    } catch (Exception $e) {
      return response()->json("error", 400);
    }

    return $this->withPeriod($expense, Source::find($expense->source_id));
  }

  /** Moving the expense: the target source must belong to the user, and not be disabled unless it is where it already is. */
  private function targetAllowed(Request $request, User $user, Expense $expense): bool {
    if (!$request->has("source_id")) {
      return true;
    }

    $target = $user->sources()->find($request->source_id);

    return $target !== null && (!$target->disabled || $target->id === $expense->source_id);
  }

  /**
   * "This and the following" of a recurring expense: the rule ends the day before the charge of `occurrence_date` and a new one starts
   * on that charge with what the request says (everything the request does not send stays as it was; its `date` is not read, the new
   * rule starts on the charge), all in one transaction. On the first charge there is nothing to keep, so the rule itself is edited. The
   * past does not change: the charges before that one are still the old rule's.
   */
  private function cut(Request $request, User $user, Expense $rule) {
    $occurrence = $request->query("occurrence_date");

    if (!$this->isCharge($rule, $occurrence) || !$this->targetAllowed($request, $user, $rule)) {
      return response()->json("error", 400);
    }

    $pick = fn(string $key) => $request->has($key) ? $request->input($key) : $rule->{$key};
    $attributes = [
      "date" => $occurrence,
      "amount" => $pick("amount"),
      "description" => $pick("description"),
      "next" => $pick("next"),
      "category_id" => $pick("category_id"),
      "source_id" => $pick("source_id"),
      "instalments" => $pick("instalments"),
      "recurs_every" => $pick("recurs_every"),
      "ends_on" => $pick("ends_on"),
    ];

    // The new rule is still a recurring one (a cut does not turn the rest into a single expense) and its category is allowed.
    if ($attributes["recurs_every"] === null || !$this->recurrenceValid($attributes["recurs_every"], $attributes["ends_on"], $occurrence, $attributes["instalments"])
      || !$this->categoryAllowed($user, $attributes["category_id"], $rule->category_id)) {
      return response()->json("error", 400);
    }

    try {
      $result = DB::transaction(function () use ($rule, $attributes, $occurrence) {
        if (substr((string) $rule->date, 0, 10) === $occurrence) {
          unset($attributes["date"]);

          return tap($rule->fill($attributes))->save();
        }

        $new = new Expense();
        $new->fill($attributes)->save();
        $rule->ends_on = Carbon::parse($occurrence)->subDay()->toDateString();
        $rule->save();

        return $new;
      });
    } catch (Exception $e) {
      return response()->json("error", 400);
    }

    // A PUT answers 200 also when it made the new rule (a model that was just created would go out as a 201).
    return response()->json($this->withPeriod($result, Source::find($result->source_id)), 200);
  }

  /**
   * Whether `$occurrence` is one of the charges of the rule: `YYYY-MM-DD`, a date of the rule plus a whole number of `recurs_every`
   * months from its own (counted from the date of the rule, as the money does, so the 31st gives the 28th in February), and not after `ends_on`.
   */
  private function isCharge(Expense $rule, mixed $occurrence): bool {
    if (!is_string($occurrence) || !$this->validDate($occurrence)) {
      return false;
    }

    $first = Carbon::parse(substr((string) $rule->date, 0, 10));
    $charge = Carbon::parse($occurrence);
    $months = ($charge->year - $first->year) * 12 + $charge->month - $first->month;

    if ($months < 0 || $months % $rule->recurs_every !== 0 || !$first->copy()->addMonthsNoOverflow($months)->isSameDay($charge)) {
      return false;
    }

    return $rule->ends_on === null || $occurrence <= substr((string) $rule->ends_on, 0, 10);
  }

  /**
   * The expense plus the billing month it falls in (`period`, `YYYY-MM`, by the cutoff of its source and `next`) and, for an
   * instalment, the last one (`period_last`; for a recurring expense that ends, the period of its last charge), so a client can show
   * the month where it ended up.
   */
  private function withPeriod(Expense $expense, Source $source): Expense {
    [$first, $last] = Summary::expensePeriods($source->cutoff, $expense->date, (bool) $expense->next, $expense->instalments, $expense->recurs_every, $expense->ends_on);
    $expense->setAttribute("period", $first);

    if ($last !== null) {
      $expense->setAttribute("period_last", $last);
    }

    return $expense;
  }

  /**
   * What `recurs_every` (a whole number of months from 1 to 60) and `ends_on` would be once the request is applied: valid, `ends_on` only on a
   * recurring expense and not before its date, and never together with instalments (an expense is one thing or the other).
   */
  private function recurrenceAllowed(Request $request, ?Expense $current): bool {
    $value = fn(string $key) => $request->has($key) ? $request->input($key) : $current?->{$key};
    $every = $value("recurs_every");
    $endsOn = $value("ends_on");

    return $this->recurrenceValid($every, $endsOn, $value("date"), $value("instalments"));
  }

  /** `recurs_every` a whole number of months from 1 to 60 or null, `ends_on` only with it and not before the date, and no instalments next to it. */
  private function recurrenceValid(mixed $every, mixed $endsOn, mixed $date, mixed $instalments): bool {
    if ($every !== null && (filter_var($every, FILTER_VALIDATE_INT) === false || (int) $every < 1 || (int) $every > 60 || $instalments !== null)) {
      return false;
    }

    if ($endsOn !== null) {
      return $every !== null && is_string($endsOn) && $this->validDate($endsOn) && is_string($date) && $endsOn >= substr($date, 0, 10);
    }

    return true;
  }

  /** A real date written as `YYYY-MM-DD`. */
  private function validDate(string $date): bool {
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $p) === 1 && checkdate((int) $p[2], (int) $p[3], (int) $p[1]);
  }

  /** No category (`null`), the one the expense already has, or one of the user's that is not disabled. */
  private function categoryAllowed(User $user, mixed $id, mixed $current): bool {
    if ($id === null || $id == $current) {
      return true;
    }

    return $user->categories()->where("id", $id)->where("disabled", false)->exists();
  }

  /**
   * Deleting a recurring expense deletes its rule and so every charge of it, past ones included, so it has to be asked for with `scope=all`: an
   * app that does not know about recurring expenses (it sees the rule as a plain expense) cannot erase it by deleting one month.
   * `scope=following` with the `occurrence_date` of a charge ends the rule the day before it (the first charge deletes the rule: nothing
   * is left of it). On anything that is not a rule the scope means nothing and is ignored.
   */
  public function destroy(Request $request, $expense_id) {
    $user = User::find(Auth::user()->id);
    $expense = Expense::find($expense_id);

    if (!$expense) {
      return response()->json("error", 400);
    }

    $source = $user->sources()
    ->where("id", $expense->source_id)
    ->first();

    if (!$source) {
      return response()->json("error", 400);
    }

    if ($expense->recurs_every !== null && $request->query("scope") !== "all") {
      if ($request->query("scope") !== "following" || !$this->isCharge($expense, $request->query("occurrence_date"))) {
        return response()->json("error", 400);
      }

      if (substr((string) $expense->date, 0, 10) !== $request->query("occurrence_date")) {
        $expense->ends_on = Carbon::parse($request->query("occurrence_date"))->subDay()->toDateString();
        $expense->save();

        return;
      }
    }

    try {
      $expense->delete();
    } catch (Exception $e) {
      return response()->json("error", 400);
    }
  }
}
