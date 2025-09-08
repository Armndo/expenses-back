<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
  protected $dateFormat = "Y-m-d H:i:sO";

  protected $fillable = [
    "name",
    "alias",
    "order",
  ];

  protected $hidden = [
    "created_at",
    "updated_at",
  ];

  public function expenses(): HasMany {
    return $this->hasMany(Expense::class);
  }
}
