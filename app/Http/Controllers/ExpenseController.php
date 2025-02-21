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
        $date = Carbon::now();
        $date->day = 1;
        $start = $date->format("Y-m-d");
        $date->month += 1;
        $date->day -= 1;
        $end = $date->format("Y-m-d");
        $expenses = $user->sources()->with(["expenses" => fn(Builder $query) =>
            $query->whereBetween("date", [$start, $end])
            ->whereNull("instalments")
            ->orderBy("date")
        ])->get()->toArray();

        return $expenses;
    }
}
