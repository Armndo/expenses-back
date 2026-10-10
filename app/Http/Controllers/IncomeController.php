<?php

namespace App\Http\Controllers;

use App\Models\Income;
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

    if (!$source || !$this->valid($request, true)) {
      return response()->json("error", 400);
    }

    try {
      $income = $source->incomes()
      ->create($request->only("date", "amount", "description"));
    } catch (Exception $e) {
      return response()->json("error", 400);
    }

    return $income;
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

    // Moving the income: the target source must also belong to the user.
    if ($request->has("source_id") && !$user->sources()->where("id", $request->source_id)->exists()) {
      return response()->json("error", 400);
    }

    try {
      $income->fill($request->only("date", "amount", "description", "source_id"));
      $income->save();
    } catch (Exception $e) {
      return response()->json("error", 400);
    }

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

  /** `amount` numeric and not zero, `date` a date; both required on create, only checked when sent on update. */
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

    return true;
  }
}
