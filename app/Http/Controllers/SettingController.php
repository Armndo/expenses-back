<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Services\Summary;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SettingController extends Controller
{
  /** The user's settings, with the defaults when they have not saved any. */
  public function show() {
    return $this->payload(User::find(Auth::user()->id));
  }

  /**
   * Sets `balance_start` (a month, `YYYY-MM` or `YYYY-MM-DD`, stored as the first of that month) and/or
   * `monthly_budget` (pesos >= 0, or null for no budget). Only the keys sent change.
   */
  public function update(Request $request) {
    $user = User::find(Auth::user()->id);
    $data = [];

    if ($request->has("balance_start")) {
      $start = $request->balance_start;

      if (!is_string($start) || !preg_match('/^(\d{4})-(\d{2})(?:-(\d{2}))?$/', $start, $parts) || !checkdate((int) $parts[2], (int) ($parts[3] ?? 1), (int) $parts[1])) {
        return response()->json("error", 400);
      }

      $data["balance_start"] = sprintf("%04d-%02d-01", $parts[1], $parts[2]);
    }

    if ($request->has("monthly_budget")) {
      $budget = $request->monthly_budget;

      if ($budget !== null && (!is_numeric($budget) || (float) $budget < 0)) {
        return response()->json("error", 400);
      }

      $data["monthly_budget"] = $budget === null ? null : (float) $budget;
    }

    try {
      Setting::updateOrCreate(["user_id" => $user->id], $data);
    } catch (Exception $e) {
      return response()->json("error", 400);
    }

    return $this->payload($user->refresh());
  }

  private function payload(User $user): array {
    $summary = new Summary($user);

    return [
      "balance_start" => $summary->balanceStart()->format("Y-m-d"),
      "monthly_budget" => $summary->budget(),
    ];
  }
}
