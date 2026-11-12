<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Summary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * `GET /period?source_id=&date=&next=&instalments=`: the billing month an expense or an income would fall in, and for an
 * instalment the last one, without saving anything. It is only informative (the forms show "Bills in ..."), so a client must not
 * depend on it: it answers `{ "period": "2026-12", "last": null }`, or `400 "error"` when something is not valid, like the rest.
 */
class PeriodController extends Controller
{
  public function __invoke(Request $request) {
    $user = User::find(Auth::user()->id);
    $date = $request->query("date");
    $source = is_scalar($request->query("source_id")) ? $user->sources()->find($request->query("source_id")) : null;

    if (!$source || !$this->validDate($date)) {
      return response()->json("error", 400);
    }

    $next = filter_var($request->query("next", false), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    $instalments = $request->query("instalments");

    if ($next === null || ($instalments !== null && $instalments !== "" && (filter_var($instalments, FILTER_VALIDATE_INT) === false || (int) $instalments < 2))) {
      return response()->json("error", 400);
    }

    $count = $instalments === null || $instalments === "" ? null : (int) $instalments;
    [$first, $last] = Summary::expensePeriods($source->cutoff, $date, $next, $count);

    return ["period" => $first, "last" => $last];
  }

  /** A real date written as `YYYY-MM-DD`. */
  private function validDate(mixed $date): bool {
    return is_string($date) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $p) === 1 && checkdate((int) $p[2], (int) $p[3], (int) $p[1]);
  }
}
