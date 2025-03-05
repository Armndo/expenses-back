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
        $date = isset($request->date) ? new Carbon($request->date) : Carbon::now();
        $date->day = 1;
        $start = $date->format("Y-m-d");
        $date->month += 1;
        $date->day -= 1;
        $end = $date->format("Y-m-d");
        $tmp = [];

        $expenses = $user->sources()->with(["expenses" => fn(Builder $query) =>
            $query->whereBetween("date", [$start, $end])
            ->whereNull("instalments")
            ->orderBy("id")
            ->orderBy("date")
        ])->withCount(["expenses" => fn(Builder $query) =>
            $query->whereNull("instalments")
            ->whereBetween("date", [$start, $end])
        ])->orderBy("sources.id")->get()->toArray();

        $instalments = $user->sources()->with(["expenses" => fn(Builder $query) =>
            $query->whereRaw("date(\"date\" + interval '1 month' * (\"instalments\" - 1)) >= '$start'")
            ->whereRaw("\"date\" <= '$end'")
            ->whereNotNull("instalments")
            ->orderBy("id")
            ->orderBy("date")
        ])->withCount(["expenses" => fn(Builder $query) =>
            $query->whereRaw("date(\"date\" + interval '1 month' * (\"instalments\" - 1)) >= '$start'")
            ->whereRaw("\"date\" <= '$end'")
            ->whereNotNull("instalments")
        ])->orderBy("sources.id")->get()->toArray();

        foreach ($expenses as $index => $source) {
            $expenses[$index]["instalments_count"] = 0;
            $expenses[$index]["instalments"] = [];
            $tmp[$source["id"]] = $index;
        }

        foreach ($instalments as $source) {
            $index = $tmp[$source["id"]];
            $expenses[$index]["instalments"] = $source["expenses"];
            $expenses[$index]["instalments_count"] = $source["expenses_count"];
        }

        return $expenses;
    }

    public function store(Request $request) {
        $user = Auth::user();
        $source = $user->sources()->where("id", $request->source_id)->first();

        if (!$source) {
            return response()->json("error", 400);
        }

        try {
            $expense = $source->expenses()->create($request->only("date", "amount", "description", "instalments"));
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
            $expense->fill($request->only("date", "amount", "description", "instalments"));
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
