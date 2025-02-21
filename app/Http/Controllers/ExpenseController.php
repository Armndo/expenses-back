<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ExpenseController extends Controller
{
    public function index(Request $request) {
        $user = Auth::user();
        $end = $request->date;
        $start = new Carbon($end);
        $start->day = 1;
        $start = $start->format("Y-m-d");
        $expenses = $user->sources()->with(["expenses" => fn(Builder $q) => $q->whereBetween("date", [$start, $end])->orderBy("date")])->get()->toArray();

        return $expenses;
    }
}
