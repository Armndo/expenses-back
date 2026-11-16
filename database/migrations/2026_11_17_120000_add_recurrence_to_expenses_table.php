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
    Schema::table('expenses', function (Blueprint $table) {
      // A recurring expense: the row is its rule and its first charge, and it repeats every `recurs_every` months (1 monthly, 12 yearly)
      // until `ends_on` (null while it goes on). Its charges are not rows: they are worked out for each period, like the months of an instalment.
      $table->smallInteger("recurs_every")->nullable()->after("instalments");
      $table->date("ends_on")->nullable()->after("recurs_every");
    });

    DB::statement("alter table expenses add constraint expenses_recurs_every_range check (recurs_every is null or recurs_every between 1 and 60)");
    DB::statement("alter table expenses add constraint expenses_recurrence_not_instalments check (recurs_every is null or instalments is null)");
    DB::statement("alter table expenses add constraint expenses_ends_on_needs_recurrence check (ends_on is null or (recurs_every is not null and ends_on >= date))");
  }

  /**
  * Reverse the migrations.
  */
  public function down(): void
  {
    DB::statement("alter table expenses drop constraint if exists expenses_ends_on_needs_recurrence");
    DB::statement("alter table expenses drop constraint if exists expenses_recurrence_not_instalments");
    DB::statement("alter table expenses drop constraint if exists expenses_recurs_every_range");

    Schema::table('expenses', function (Blueprint $table) {
      $table->dropColumn(["recurs_every", "ends_on"]);
    });
  }
};
