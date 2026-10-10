<?php

namespace App\Http\Controllers;

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

  /** Sets the currency of one of the user's sources (`currency` is one of `expenses.currencies`). */
  public function update(Request $request, $source_id) {
    $user = User::find(Auth::user()->id);
    $source = $user->sources()->find($source_id);

    if (!$source || !is_string($request->currency) || !in_array($request->currency, config("expenses.currencies", ["MXN", "USD"]), true)) {
      return response()->json("error", 400);
    }

    try {
      $source->currency = $request->currency;
      $source->save();
    } catch (Exception $e) {
      return response()->json("error", 400);
    }

    return $source;
  }
}
