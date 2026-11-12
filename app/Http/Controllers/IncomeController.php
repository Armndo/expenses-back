<?php

namespace App\Http\Controllers;

use App\Models\Income;
use App\Models\Source;
use App\Services\Summary;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class IncomeController extends Controller
{
  public function store(Request $request) {
    $user = User::find(Auth::user()->id);
    $source = $user->sources()
    ->where("id", $request->source_id)
    ->first();

    // A disabled source takes no new movements.
    if (!$source || $source->disabled || !$this->valid($request, true)) {
      return response()->json("error", 400);
    }

    try {
      $income = $source->incomes()
      ->create($request->only("date", "amount", "description", "skip_budget"));
    } catch (Exception $e) {
      return response()->json("error", 400);
    }

    return $this->withPeriod($income, $source);
  }

  public function update(Request $request, $income_id) {
    $user = User::find(Auth::user()->id);
    $income = Income::find($income_id);

    if (!$income) {
      return response()->json("error", 400);
    }

    $source = $user->sources()
    ->where("id", $income->source_id)
    ->first();

    if (!$source || !$this->valid($request, false)) {
      return response()->json("error", 400);
    }

    // Moving the income: the target source must also belong to the user, and not be disabled unless it is where it already is.
    if ($request->has("source_id")) {
      $target = $user->sources()->find($request->source_id);

      if (!$target || ($target->disabled && $target->id !== $income->source_id)) {
        return response()->json("error", 400);
      }
    }

    try {
      $income->fill($request->only("date", "amount", "description", "skip_budget", "source_id"));
      $income->save();
    } catch (Exception $e) {
      return response()->json("error", 400);
    }

    return $this->withPeriod($income, Source::find($income->source_id));
  }

  /** The income plus the billing month it falls in (`period`, `YYYY-MM`), by the cutoff of its source, like an expense. */
  private function withPeriod(Income $income, Source $source): Income {
    $income->setAttribute("period", Summary::periodOf($source->cutoff, $income->date));

    return $income;
  }

  public function destroy($income_id) {
    $user = User::find(Auth::user()->id);
    $income = Income::find($income_id);

    if (!$income) {
      return response()->json("error", 400);
    }

    $source = $user->sources()
    ->where("id", $income->source_id)
    ->first();

    if (!$source) {
      return response()->json("error", 400);
    }

    try {
      $income->delete();
    } catch (Exception $e) {
      return response()->json("error", 400);
    }
  }

  /**
   * `amount` numeric and not zero, `date` a date; both required on create, only checked when sent on update.
   * `skip_budget` is optional (false by default) and must be a boolean when sent.
   */
  private function valid(Request $request, bool $creating): bool {
    if ($creating || $request->has("amount")) {
      if (!is_numeric($request->amount) || (float) $request->amount == 0) {
        return false;
      }
    }

    if ($creating || $request->has("date")) {
      if (!is_string($request->date) || strtotime($request->date) === false) {
        return false;
      }
    }

    if ($request->has("skip_budget") && filter_var($request->skip_budget, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
      return false;
    }

    return true;
  }
}
