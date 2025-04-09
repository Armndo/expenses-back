<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Expense extends Model
{
    protected $dateFormat = "Y-m-d H:i:sO";

    protected $fillable = [
        "amount",
        "description",
        "date",
        "instalments",
        "source_id",
    ];

    protected $hidden = [
        "created_at",
        "updated_at",
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
