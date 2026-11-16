<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Source;
use App\Services\Summary;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

    // Moving the expense: the target source must also belong to the user, and not be disabled unless it is where it already is.
    if ($request->has("source_id")) {
      $target = $user->sources()->find($request->source_id);

      if (!$target || ($target->disabled && $target->id !== $expense->source_id)) {
        return response()->json("error", 400);
      }
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
    $date = $value("date");

    if ($every !== null && (filter_var($every, FILTER_VALIDATE_INT) === false || (int) $every < 1 || (int) $every > 60 || $value("instalments") !== null)) {
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

    if (!$source || ($expense->recurs_every !== null && $request->query("scope") !== "all")) {
      return response()->json("error", 400);
    }

    try {
      $expense->delete();
    } catch (Exception $e) {
      return response()->json("error", 400);
    }
  }
}
