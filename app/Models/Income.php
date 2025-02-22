<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Income extends Model
{
    protected $fillable = [
        "amount",
        "date",
    ];

    public $timestamps = true;

    public function source() {
        return $this->belongsTo(Source::class);
    }
}
