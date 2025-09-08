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
    Schema::create('sources', function (Blueprint $table) {
      $table->id();
      $table->text("name");
      $table->timestampsTz(2);
      $table->softDeletesTz("deleted_at", 2);
      $table->unsignedBigInteger("user_id");
      $table->foreign("user_id")->references("id")->on("users")->onUpdate("cascade");
    });
  }

  /**
  * Reverse the migrations.
  */
  public function down(): void
  {
    Schema::dropIfExists('sources');
  }
};
