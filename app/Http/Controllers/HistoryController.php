<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Summary;
use Illuminate\Support\Facades\Auth;

class HistoryController extends Controller
{
  /** The month by month `summary` since the first expense, up to the current month. */
  public function __invoke() {
    return ["months" => (new Summary(User::find(Auth::user()->id)))->history()];
  }
}
