<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $fillable = [
        "amount",
        "description",
        "date",
        "is_monthly",
        "monthly_amount",
        "instalments",
    ];

    protected $hidden = [
        "created_at",
        "updated_at",
        "deleted_at",
        "source_id",
    ];

    protected $casts = [
        "amount" => "float",
    ];

    public $timestamps = true;

    public function source() {
        return $this->belongsTo(Source::class);
    }
}
