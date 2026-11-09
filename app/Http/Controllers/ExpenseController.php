<?php

namespace App\Http\Controllers;

use App\Models\Expense;
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
    if (!$source || $source->disabled || !$this->categoryAllowed($user, $request->category_id, null)) {
      return response()->json("error", 400);
    }

    try {
      $expense = $source->expenses()
      ->create($request->only("date", "next", "amount", "description", "category_id", "instalments"));
    } catch (Exception $e) {
      return response()->json("error", 400);
    }

    return $expense;
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

    try {
      $expense->fill($request->only("date", "next", "amount", "description", "instalments", "category_id", "source_id"));
      $expense->save();
    } catch (Exception $e) {
      return response()->json("error", 400);
    }

    return $expense;
  }

  /** No category (`null`), the one the expense already has, or one of the user's that is not disabled. */
  private function categoryAllowed(User $user, mixed $id, mixed $current): bool {
    if ($id === null || $id == $current) {
      return true;
    }

    return $user->categories()->where("id", $id)->where("disabled", false)->exists();
  }

  public function destroy($expense_id) {
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

    try {
      $expense->delete();
    } catch (Exception $e) {
      return response()->json("error", 400);
    }
  }
}
