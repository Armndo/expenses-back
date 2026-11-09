<?php

namespace App\Providers;

use Carbon\Carbon;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
  /**
  * Register any application services.
  */
  public function register(): void
  {
    //
  }

  /**
  * Bootstrap any application services.
  */
  public function boot(): void
  {
    // Keep the integer ids of the OAuth clients: the tokens already issued are bound to the id of their client, and
    // changing it would invalidate all of them.
    Passport::$clientUuids = false;
    Passport::personalAccessTokensExpireIn(Carbon::now()->addDay());
  }
}
