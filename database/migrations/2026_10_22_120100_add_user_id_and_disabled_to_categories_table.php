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
    Schema::table('categories', function (Blueprint $table) {
      $table->unsignedBigInteger("user_id")->nullable()->after("id");
      $table->boolean("disabled")->default(false);
    });

    $this->assignToUsers();

    Schema::table('categories', function (Blueprint $table) {
      $table->unsignedBigInteger("user_id")->nullable(false)->change();
      $table->foreign("user_id")->references("id")->on("users")->onUpdate("cascade");
      $table->index("user_id");
    });
  }

  /**
   * The categories were shared by every user: each one goes to the user whose expenses use it. When several users use
   * the same one, the first keeps it and the others get a copy, with their expenses pointed at it. A category nobody
   * uses goes to the first user.
   */
  private function assignToUsers(): void {
    $categories = DB::table("categories")->get();

    if ($categories->isEmpty()) {
      return;
    }

    $first = DB::table("users")->min("id");

    if ($first === null) {
      throw new RuntimeException("There are categories but no users to give them to.");
    }

    foreach ($categories as $category) {
      $owners = DB::table("expenses")
      ->join("sources", "sources.id", "expenses.source_id")
      ->where("expenses.category_id", $category->id)
      ->distinct()
      ->orderBy("sources.user_id")
      ->pluck("sources.user_id");

      DB::table("categories")->where("id", $category->id)->update(["user_id" => $owners->first() ?? $first]);

      foreach ($owners->skip(1) as $other) {
        $copy = DB::table("categories")->insertGetId([
          "user_id" => $other,
          "name" => $category->name,
          "alias" => $category->alias,
          "order" => $category->order,
          "color" => $category->color,
          "disabled" => false,
          "created_at" => now(),
          "updated_at" => now(),
        ]);

        DB::table("expenses")
        ->where("category_id", $category->id)
        ->whereIn("source_id", DB::table("sources")->where("user_id", $other)->select("id"))
        ->update(["category_id" => $copy]);
      }
    }
  }

  /**
  * Reverse the migrations. The copies made for other users stay as separate categories.
  */
  public function down(): void
  {
    Schema::table('categories', function (Blueprint $table) {
      $table->dropForeign(["user_id"]);
      $table->dropIndex(["user_id"]);
      $table->dropColumn(["user_id", "disabled"]);
    });
  }
};
