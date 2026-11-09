<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
  use HasFactory;

  protected $dateFormat = "Y-m-d H:i:sO";

  protected $fillable = [
    "name",
    "alias",
    "order",
    "color",
  ];

  protected $hidden = [
    "user_id",
    "created_at",
    "updated_at",
  ];

  protected $casts = [
    "disabled" => "boolean",
  ];

  public function user() {
    return $this->belongsTo(User::class);
  }

  public function expenses(): HasMany {
    return $this->hasMany(Expense::class);
  }
}
