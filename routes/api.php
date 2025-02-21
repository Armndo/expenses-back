<?php

use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post("login", [UserController::class, "login"]);
Route::get("info", [UserController::class, "info"])->middleware("auth:api");
Route::get("expenses", [ExpenseController::class, "index"])->middleware("auth:api");