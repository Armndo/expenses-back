<?php

namespace App\Http\Controllers;

use App\Models\Transfer;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TransferController extends Controller
{
  public function store(Request $request) {
    $user = User::find(Auth::user()->id);

    if (!$this->valid($request, $user, null)) {
      return response()->json("error", 400);
    }

    try {
      $transfer = Transfer::create($request->only("from_source_id", "to_source_id", "amount", "received_amount", "date", "description"));
    } catch (Exception $e) {
      return response()->json("error", 400);
    }

    return $transfer;
  }

  public function update(Request $request, $transfer_id) {
    $user = User::find(Auth::user()->id);
    $transfer = Transfer::find($transfer_id);

    if (!$transfer || !$this->owns($user, $transfer) || !$this->valid($request, $user, $transfer)) {
      return response()->json("error", 400);
    }

    try {
      $transfer->fill($request->only("from_source_id", "to_source_id", "amount", "received_amount", "date", "description"));
      $transfer->save();
    } catch (Exception $e) {
      return response()->json("error", 400);
    }

    return $transfer;
  }

  public function destroy($transfer_id) {
    $user = User::find(Auth::user()->id);
    $transfer = Transfer::find($transfer_id);

    if (!$transfer || !$this->owns($user, $transfer)) {
      return response()->json("error", 400);
    }

    try {
      $transfer->delete();
    } catch (Exception $e) {
      return response()->json("error", 400);
    }
  }

  /** Both ends of an existing transfer belong to the user. */
  private function owns(User $user, Transfer $transfer): bool {
    return $user->sources()->whereIn("id", [$transfer->from_source_id, $transfer->to_source_id])->count() === count(array_unique([$transfer->from_source_id, $transfer->to_source_id]));
  }

  /**
   * Both sources are the user's and different, `amount` is a positive number, `date` a date and
   * `received_amount` a positive number when the currencies of the two sources differ and empty when they
   * are the same. On update ($current set) only what is sent is checked,
   * against the transfer's current values for the rest.
   */
  private function valid(Request $request, User $user, ?Transfer $current): bool {
    $from = $request->has("from_source_id") || !$current ? $request->from_source_id : $current->from_source_id;
    $to = $request->has("to_source_id") || !$current ? $request->to_source_id : $current->to_source_id;

    if (!$from || !$to || $from == $to || $user->sources()->whereIn("id", [$from, $to])->count() !== 2) {
      return false;
    }

    if (!$current || $request->has("amount")) {
      if (!is_numeric($request->amount) || (float) $request->amount <= 0) {
        return false;
      }
    }

    if ($request->filled("received_amount") && (!is_numeric($request->received_amount) || (float) $request->received_amount <= 0)) {
      return false;
    }

    // Between currencies the amount that arrives is required; within one it must be empty (it is the same).
    $received = $request->has("received_amount") || !$current ? $request->received_amount : $current->received_amount;
    $currencies = $user->sources()->whereIn("id", [$from, $to])->pluck("currency", "id");

    if (($currencies[$from] !== $currencies[$to]) !== ($received !== null && $received !== "")) {
      return false;
    }

    if (!$current || $request->has("date")) {
      if (!is_string($request->date) || strtotime($request->date) === false) {
        return false;
      }
    }

    return true;
  }
}
