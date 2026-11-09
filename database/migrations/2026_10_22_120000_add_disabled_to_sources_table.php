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
    Schema::table('sources', function (Blueprint $table) {
      // A disabled source is hidden from the pickers and takes no new movements; it is never deleted and its money still counts.
      $table->boolean("disabled")->default(false)->after("currency");
    });
  }

  /**
  * Reverse the migrations.
  */
  public function down(): void
  {
    Schema::table('sources', function (Blueprint $table) {
      $table->dropColumn("disabled");
    });
  }
};
