<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
  protected $dateFormat = "Y-m-d H:i:sO";

  protected $fillable = [
    "user_id",
    "currency",
    "rate",
  ];

  protected $hidden = [
    "user_id",
    "created_at",
    "updated_at",
  ];

  protected $casts = [
    "rate" => "float",
  ];

  public function user() {
    return $this->belongsTo(User::class);
  }
}
