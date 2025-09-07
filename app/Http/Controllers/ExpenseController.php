<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ExpenseController extends Controller
{
    public function store(Request $request) {
        $user = Auth::user();
        $source = $user->sources()->where("id", $request->source_id)->first();

        if (!$source) {
            return response()->json("error", 400);
        }

        try {
            $expense = $source->expenses()->create($request->only("date", "amount", "description", "category_id", "instalments"));
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
            $expense->fill($request->only("date", "amount", "description", "instalments", "category_id", "source_id"));
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
