<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  /**
  * Run the migrations.
  */
  public function up(): void
  {
    Schema::table('settings', function (Blueprint $table) {
      // First month the monthly budget counts from (and starts carrying over); null means the balance start.
      $table->date("budget_start")->nullable()->after("balance_start");
    });
  }

  /**
  * Reverse the migrations.
  */
  public function down(): void
  {
    Schema::table('settings', function (Blueprint $table) {
      $table->dropColumn("budget_start");
    });
  }
};
