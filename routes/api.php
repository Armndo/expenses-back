<?php

use App\Http\Controllers\SourceController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post("login", [UserController::class, "login"]);
Route::get("info", [UserController::class, "info"])->middleware("auth:api");
Route::get("sources", [SourceController::class, "index"])->middleware("auth:api");