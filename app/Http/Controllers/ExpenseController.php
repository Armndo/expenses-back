<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Carbon\Carbon;
use Exception;
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
            ->orderBy("id")
            ->orderBy("date")
        ])->withCount("expenses")->orderBy("sources.id")->get()->toArray();

        return $expenses;
    }

    public function store(Request $request) {
        $user = Auth::user();
        $source = $user->sources()->where("id", $request->source_id)->first();

        if (!$source) {
            return response()->json("error", 400);
        }

        try {
            $expense = $source->expenses()->create($request->only("date", "amount", "description"));
        } catch (Exception $e) {
            return response()->json("error", 400);
        }

        return $expense;
    }

    public function update(Request $request, $expense_id) {
        $user = Auth::user();
        $source = $user->sources()->where("id", $request->source_id)->first();

        if (!$source) {
            return response()->json("error", 400);
        }

        $expense = $source->expenses()->where("id", $expense_id)->first();

        if (!$expense) {
            return response()->json("error", 400);
        }

        try {
            $expense->fill($request->only("date", "amount", "description"));
            $expense->save();
        } catch (Exception $e) {
            return response()->json("error", 400);
        }

        return $expense;
    }

    public function destroy($expense_id) {
        $user = Auth::user();
        $expense = Expense::find($expense_id);

        if (!$expense) {
            return response()->json("error", 400);
        }

        $source = $user->sources()->where("id", $expense->source_id)->first();

        if (!$source) {
            return response()->json("error", 400);
        }

        try {
            $expense->delete();
        } catch (Exception $e) {
            return response()->json("error", 400);
        }
    }
}
