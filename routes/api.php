<?php

use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post("login", [UserController::class, "login"]);
Route::get("info", [UserController::class, "info"])->middleware("auth:api");
Route::get("expenses", [ExpenseController::class, "index"])->middleware("auth:api");
Route::post("expenses", [ExpenseController::class, "store"])->middleware("auth:api");
Route::put("expenses/{expense_id}", [ExpenseController::class, "update"])->middleware("auth:api");
Route::delete("expenses/{expense_id}", [ExpenseController::class, "destroy"])->middleware("auth:api");