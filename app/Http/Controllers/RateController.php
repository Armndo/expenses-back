<?php

namespace App\Http\Controllers;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\Summary;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RateController extends Controller
{
  /** Sets the pesos per unit of `$currency` (any currency of `expenses.currencies` but the base one). */
  public function update(Request $request, string $currency) {
    $user = User::find(Auth::user()->id);
    $currency = strtoupper($currency);

    if (
      !in_array($currency, config("expenses.currencies", ["MXN", "USD"]), true)
      || $currency === Summary::baseCurrency()
      || !is_numeric($request->rate)
      || (float) $request->rate <= 0
    ) {
      return response()->json("error", 400);
    }

    try {
      $rate = ExchangeRate::updateOrCreate(
        ["user_id" => $user->id, "currency" => $currency],
        ["rate" => (float) $request->rate],
      );
    } catch (Exception $e) {
      return response()->json("error", 400);
    }

    return $rate;
  }
}
