<?php

use App\Http\Controllers\AppController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\IncomeController;
use App\Http\Controllers\RateController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\TransferController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post("login", [UserController::class, "login"]);

Route::middleware("auth:api")->group(function() {
  Route::controller(UserController::class)->group(function() {
    Route::post("logout", "logout");
    Route::get("info", "info");
  });

  Route::get("data", AppController::class);
  Route::get("history", HistoryController::class);

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

  Route::controller(TransferController::class)->group(function() {
    Route::post("transfers", "store");
    Route::put("transfers/{transfer_id}", "update");
    Route::delete("transfers/{transfer_id}", "destroy");
  });

  Route::controller(SourceController::class)->group(function() {
    Route::post("sources", "store");
    Route::put("sources/{source_id}", "update");
  });
  Route::controller(CategoryController::class)->group(function() {
    Route::post("categories", "store");
    Route::put("categories/{category_id}", "update");
  });
  Route::put("rates/{currency}", [RateController::class, "update"]);

  Route::controller(SettingController::class)->group(function() {
    Route::get("settings", "show");
    Route::put("settings", "update");
  });
});
