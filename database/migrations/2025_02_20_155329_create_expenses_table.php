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
    Schema::create('expenses', function (Blueprint $table) {
      $table->id();
      $table->float("amount")->default(0);
      $table->text("description")->nullable();
      $table->date("date");
      $table->boolean("is_monthly")->default(false);
      $table->float("monthly_amount")->nullable();
      $table->smallInteger("instalments")->nullable();
      $table->timestampsTz(2);
      $table->softDeletesTz("deleted_at", 2);
      $table->unsignedBigInteger("source_id");
      $table->foreign("source_id")->references("id")->on("sources")->onUpdate("cascade");
    });
  }

  /**
  * Reverse the migrations.
  */
  public function down(): void
  {
    Schema::dropIfExists('expenses');
  }
};
