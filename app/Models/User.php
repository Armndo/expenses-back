<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
  protected $dateFormat = "Y-m-d H:i:sO";

  /** @use HasFactory<\Database\Factories\UserFactory> */
  use HasFactory, Notifiable, HasApiTokens;

  /**
  * The attributes that are mass assignable.
  *
  * @var list<string>
  */
  protected $fillable = [
    'username',
    'name',
    'lastname',
    'email',
    'password',
  ];

  /**
  * The attributes that should be hidden for serialization.
  *
  * @var list<string>
  */
  protected $hidden = [
    'password',
    'remember_token',
    'created_at',
    'updated_at',
    'email_verified_at',
  ];

  /**
  * Get the attributes that should be cast.
  *
  * @return array<string, string>
  */
  protected function casts(): array
  {
    return [
      'email_verified_at' => 'datetime',
      'password' => 'hashed',
    ];
  }

  public function sources() {
    return $this->hasMany(Source::class);
  }
}
