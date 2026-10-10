<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transfer extends Model
{
  use HasFactory;

  protected $dateFormat = "Y-m-d H:i:sO";

  protected $fillable = [
    "from_source_id",
    "to_source_id",
    "amount",
    "received_amount",
    "date",
    "description",
  ];

  protected $hidden = [
    "created_at",
    "updated_at",
  ];

  protected $casts = [
    "amount" => "float",
    "received_amount" => "float",
  ];

  public $timestamps = true;

  public function from() {
    return $this->belongsTo(Source::class, "from_source_id");
  }

  public function to() {
    return $this->belongsTo(Source::class, "to_source_id");
  }
}
