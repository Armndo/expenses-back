<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Source extends Model
{
  use HasFactory;

  protected $dateFormat = "Y-m-d H:i:sO";

  protected $fillable = [
    "name",
    "cutoff",
    "currency",
  ];

  protected $hidden = [
    "user_id",
    "created_at",
    "updated_at",
  ];

  protected $appends = [
    "kind",
  ];

  public function user() {
    return $this->belongsTo(User::class);
  }

  public function expenses() {
    return $this->hasMany(Expense::class);
  }

  public function incomes() {
    return $this->hasMany(Income::class);
  }

  public function outgoingTransfers() {
    return $this->hasMany(Transfer::class, "from_source_id");
  }

  public function incomingTransfers() {
    return $this->hasMany(Transfer::class, "to_source_id");
  }

  /** A source with a cutoff (even 0) is a credit card; without one it is a debit account. */
  public function isCard(): bool {
    return $this->cutoff !== null;
  }

  public function getKindAttribute(): string {
    return $this->isCard() ? "card" : "account";
  }
}
