<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Source extends Model
{
    protected $dateFormat = "Y-m-d H:i:sO";

    protected $fillable = [
        "name",
    ];

    protected $hidden = [
        "user_id",
        "created_at",
        "updated_at",
    ];

    public $timestamps = true;

    public function user() {
        return $this->belongsTo(User::class);
    }

    public function expenses() {
        return $this->hasMany(Expense::class);
    }

    public function incomes() {
        return $this->hasMany(Income::class);
    }
}
