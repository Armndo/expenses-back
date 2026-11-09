<?php

namespace App\Http\Controllers;

use App\Models\Source;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SourceController extends Controller
{
  public function index() {
    $user = Auth::user();

    return $user->sources;
  }

  /**
   * Creates a source of the user: `name` is required, `cutoff` makes it a credit card (`null` or missing, a debit
   * account), `currency` defaults to the base one and `disabled` to `false`.
   */
  public function store(Request $request) {
    $user = User::find(Auth::user()->id);

    if (!$this->valid($request, $user, null)) {
      return response()->json("error", 400);
    }

    try {
      $source = $user->sources()->create([
        "name" => trim($request->name),
        "cutoff" => $request->has("cutoff") ? $this->cutoff($request->cutoff) : null,
        "currency" => $request->has("currency") ? $request->currency : config("expenses.currencies", ["MXN"])[0],
      ]);

      if ($request->has("disabled")) {
        $source->disabled = filter_var($request->disabled, FILTER_VALIDATE_BOOLEAN);
        $source->save();
      }
    } catch (Exception $e) {
      return response()->json("error", 400);
    }

    return $source->fresh();
  }

  /**
   * Changes one of the user's sources: only the fields that are sent (`name`, `cutoff`, `currency`, `disabled`). A
   * source is never deleted; `disabled` hides it from the pickers and stops it taking new movements, but its money
   * still counts. A kind change (account to card or back) is refused when it already has movements.
   */
  public function update(Request $request, $source_id) {
    $user = User::find(Auth::user()->id);
    $source = $user->sources()->find($source_id);

    if (!$source || !$this->valid($request, $user, $source)) {
      return response()->json("error", 400);
    }

    try {
      if ($request->has("name")) {
        $source->name = trim($request->name);
      }

      if ($request->has("cutoff")) {
        $source->cutoff = $this->cutoff($request->cutoff);
      }

      if ($request->has("currency")) {
        $source->currency = $request->currency;
      }

      if ($request->has("disabled")) {
        $source->disabled = filter_var($request->disabled, FILTER_VALIDATE_BOOLEAN);
      }

      $source->save();
    } catch (Exception $e) {
      return response()->json("error", 400);
    }

    return $source;
  }

  /** `null`, or a whole number from 0 to 27 (28 or more would make February overlap March), as an integer. */
  private function cutoff(mixed $value): ?int {
    return $value === null ? null : (int) $value;
  }

  private function validCutoff(mixed $value): bool {
    if ($value === null) {
      return true;
    }

    if (is_bool($value) || is_array($value)) {
      return false;
    }

    $number = filter_var($value, FILTER_VALIDATE_INT);

    return $number !== false && $number >= 0 && $number <= 27;
  }

  /**
   * `name` (required on create) is a trimmed, non-empty string of up to 50 characters, unique among the user's sources
   * whatever the case; `cutoff` is `null` or 0..27; `currency` one of `expenses.currencies`; `disabled` a boolean. On
   * update ($current set) only what is sent is checked, and the kind can only change while there are no movements.
   */
  private function valid(Request $request, User $user, ?Source $current): bool {
    if (!$current || $request->has("name")) {
      $name = $request->name;

      if (!is_string($name) || trim($name) === "" || mb_strlen(trim($name)) > 50) {
        return false;
      }

      $same = $user->sources()->whereRaw("lower(name) = ?", [mb_strtolower(trim($name))]);

      if ($current) {
        $same->where("id", "!=", $current->id);
      }

      if ($same->exists()) {
        return false;
      }
    }

    if ($request->has("cutoff")) {
      if (!$this->validCutoff($request->cutoff)) {
        return false;
      }

      $becomesCard = $request->cutoff !== null;

      if ($current && $becomesCard !== $current->isCard() && $current->hasMovements()) {
        return false;
      }
    }

    if ($request->has("currency")) {
      if (!is_string($request->currency) || !in_array($request->currency, config("expenses.currencies", ["MXN", "USD"]), true)) {
        return false;
      }
    }

    if ($request->has("disabled") && filter_var($request->disabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
      return false;
    }

    return true;
  }
}
