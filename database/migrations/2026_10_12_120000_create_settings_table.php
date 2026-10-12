<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  /**
  * Run the migrations.
  */
  public function up(): void
  {
    Schema::create('settings', function (Blueprint $table) {
      $table->id();
      $table->unsignedBigInteger("user_id")->unique();
      $table->foreign("user_id")->references("id")->on("users")->onUpdate("cascade");
      // First month counted by the running balance; null falls back to `config("expenses.balance_start")`.
      $table->date("balance_start")->nullable();
      // Pesos a month the spending is compared with; null means no budget.
      $table->float("monthly_budget")->nullable();
      $table->timestampsTz(2);
    });

    // The balance start used to live in the config: keep it for the users that already exist.
    $start = config("expenses.balance_start") ?? "2026-09-01";

    DB::table("users")->pluck("id")->each(fn($id) => DB::table("settings")->insert([
      "user_id" => $id,
      "balance_start" => $start,
      "created_at" => now(),
      "updated_at" => now(),
    ]));
  }

  /**
  * Reverse the migrations.
  */
  public function down(): void
  {
    Schema::dropIfExists('settings');
  }
};
