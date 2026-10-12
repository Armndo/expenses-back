<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
  protected $dateFormat = "Y-m-d H:i:sO";

  protected $fillable = [
    "user_id",
    "balance_start",
    "budget_start",
    "monthly_budget",
  ];

  protected $hidden = [
    "id",
    "user_id",
    "created_at",
    "updated_at",
  ];

  protected $casts = [
    "balance_start" => "date:Y-m-d",
    "budget_start" => "date:Y-m-d",
    "monthly_budget" => "float",
  ];

  public function user() {
    return $this->belongsTo(User::class);
  }
}
