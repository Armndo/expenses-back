<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Income extends Model
{
  use HasFactory;

  protected $dateFormat = "Y-m-d H:i:sO";

  protected $fillable = [
    "amount",
    "date",
    "description",
    "skip_budget",
    "source_id",
  ];

  protected $hidden = [
    "created_at",
    "updated_at",
    "source_id",
  ];

  protected $attributes = [
    "skip_budget" => false,
  ];

  protected $casts = [
    "amount" => "float",
    "skip_budget" => "boolean",
  ];

  public $timestamps = true;

  public function source() {
    return $this->belongsTo(Source::class);
  }
}
