<?php

use App\Http\Controllers\AppController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\IncomeController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post("login", [UserController::class, "login"]);

Route::middleware("auth:api")->group(function() {
  Route::controller(UserController::class)->group(function() {
    Route::post("logout", "logout");
    Route::get("info", "info");
  });

  Route::get("data", AppController::class);

  Route::controller(ExpenseController::class)->group(function() {
    Route::post("expenses", "store");
    Route::put("expenses/{expense_id}", "update");
    Route::delete("expenses/{expense_id}", "destroy");
  });

  Route::controller(IncomeController::class)->group(function() {
    Route::post("incomes", "store");
    Route::put("incomes/{income_id}", "update");
    Route::delete("incomes/{income_id}", "destroy");
  });
});
