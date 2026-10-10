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
    Schema::create('transfers', function (Blueprint $table) {
      $table->id();
      $table->unsignedBigInteger("from_source_id");
      $table->foreign("from_source_id")->references("id")->on("sources")->onUpdate("cascade");
      $table->unsignedBigInteger("to_source_id");
      $table->foreign("to_source_id")->references("id")->on("sources")->onUpdate("cascade");
      $table->float("amount");
      // What arrives in the destination's currency; null means the same as `amount`.
      $table->float("received_amount")->nullable();
      $table->date("date");
      $table->text("description")->nullable();
      $table->timestampsTz(2);
    });
  }

  /**
  * Reverse the migrations.
  */
  public function down(): void
  {
    Schema::dropIfExists('transfers');
  }
};
