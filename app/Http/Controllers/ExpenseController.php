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
            $query->select("expenses.*")
            ->join("sources", "sources.id", "expenses.source_id")
            ->whereRaw("date between date(date_trunc('month', '$start'::date)::date + coalesce(sources.cutoff, 0)) and date(date_trunc('month', '$start'::date)::date + interval '1 month') - 1 + coalesce(sources.cutoff, 0)")
            ->whereNull("instalments")
            ->orderBy("date")
            ->orderBy("expenses.id")
        ])->orderBy("sources.id")->get()->toArray();

        $instalments = $user->sources()->with(["expenses" => fn(Builder $query) =>
            $query->select("expenses.*")
            ->join("sources", "sources.id", "expenses.source_id")
            ->whereRaw("date(\"date\" + interval '1 month' * (\"instalments\" - 1)) >= date(date_trunc('month', '$start'::date)::date + coalesce(sources.cutoff, 0))")
            ->whereRaw("\"date\" <= date(date_trunc('month', '$start'::date)::date + interval '1 month') - 1 + coalesce(sources.cutoff, 0)")
            ->whereNotNull("instalments")
            ->orderBy("date")
            ->orderBy("id")
        ])->orderBy("sources.id")->get()->toArray();

        foreach ($expenses as $index => $source) {
            $expenses[$index]["expenses_count"] = sizeof($source["expenses"]);
            $expenses[$index]["instalments_count"] = 0;
            $expenses[$index]["instalments"] = [];
            $tmp[$source["id"]] = $index;
        }

        foreach ($instalments as $source) {
            $index = $tmp[$source["id"]];
            $expenses[$index]["instalments"] = $source["expenses"];
            $expenses[$index]["instalments_count"] = sizeof($source["expenses"]);
        }

        $incomes = $user->sources()->with(["incomes" => fn(Builder $query) =>
            $query->whereBetween("date", [$start, $end])
            ->orderBy("date")
            ->orderBy("id")
        ])->withCount(["incomes" => fn(Builder $query) =>
            $query->whereBetween("date", [$start, $end])
        ])->orderBy("sources.id")->get()->toArray();

        foreach ($incomes as $source) {
            $index = $tmp[$source["id"]];
            $expenses[$index]["incomes"] = $source["incomes"];
            $expenses[$index]["incomes_count"] = $source["incomes_count"];
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
        $expense = Expense::find($expense_id);

        if (!$expense) {
            return response()->json("error", 400);
        }

        $source = $user->sources()->where("id", $expense->source_id)->first();

        if (!$source) {
            return response()->json("error", 400);
        }

        try {
            $expense->fill($request->only("date", "amount", "description", "instalments", "source_id"));
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
