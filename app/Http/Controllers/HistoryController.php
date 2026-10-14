<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Summary;
use Illuminate\Support\Facades\Auth;

class HistoryController extends Controller
{
  /**
   * The month by month `summary` over the range of the user (the months after the current one are a projection) and the
   * spending pace that projection assumes for the budget (`null` without a budget).
   */
  public function __invoke() {
    $summary = new Summary(User::find(Auth::user()->id));

    return [
      "months" => $summary->history(),
      "budget_pace" => $summary->budget() === null ? null : $summary->spendingPace(),
    ];
  }
}
