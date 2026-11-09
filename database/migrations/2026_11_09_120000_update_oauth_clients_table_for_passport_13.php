<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Passport;

/**
 * Passport 13 describes a client with `owner_type`/`owner_id`, `redirect_uris` and `grant_types`, in place of `user_id`,
 * `redirect`, `personal_access_client` and `password_client`. The old columns still work, but with integer client ids a user
 * whose id is the id of a client would have its token requests rejected, so the new structure is applied (it keeps the ids:
 * the tokens already issued stay valid). `oauth_personal_access_clients` is no longer read and is left in place.
 *
 * The grants are written on purpose. Passport guesses `client_credentials` for a client with a secret and no owner, and
 * rejects a token when the user id is the id of a client that has that grant (the user 1 and the personal access client 1
 * would be locked out). A personal access client only needs `personal_access`, and the password one `password`.
 */
return new class extends Migration
{
  public function up(): void
  {
    Schema::table('oauth_clients', function (Blueprint $table) {
      $table->nullableMorphs('owner', after: 'user_id');

      $table->after('provider', function (Blueprint $table) {
        $table->text('redirect_uris')->nullable();
        $table->text('grant_types')->nullable();
      });
    });

    foreach (Passport::client()->cursor() as $client) {
      Model::withoutTimestamps(fn () => $client->forceFill([
        'owner_id' => $client->user_id,
        'owner_type' => $client->user_id
          ? config('auth.providers.'.($client->provider ?: config('auth.guards.api.provider')).'.model')
          : null,
        'redirect_uris' => $client->redirect_uris,
        'grant_types' => $this->grantTypes($client),
      ])->save());
    }

    Schema::table('oauth_clients', function (Blueprint $table) {
      $table->dropColumn(['user_id', 'redirect', 'personal_access_client', 'password_client']);

      $table->text('redirect_uris')->nullable(false)->change();
      $table->text('grant_types')->nullable(false)->change();
    });
  }

  /** The grants of a client: only the one it is for, so none of them can be mistaken for a client credentials token. */
  private function grantTypes($client): array
  {
    return match (true) {
      (bool) $client->personal_access_client => ['personal_access'],
      (bool) $client->password_client => ['password', 'refresh_token'],
      default => $client->grant_types,
    };
  }

  public function down(): void
  {
    Schema::table('oauth_clients', function (Blueprint $table) {
      $table->unsignedBigInteger('user_id')->nullable()->index()->after('id');
      $table->text('redirect')->nullable();
      $table->boolean('personal_access_client')->default(false);
      $table->boolean('password_client')->default(false);
    });

    DB::table('oauth_clients')->orderBy('id')->each(function ($client) {
      $grants = json_decode($client->grant_types, true) ?: [];
      $uris = json_decode($client->redirect_uris, true) ?: [];

      DB::table('oauth_clients')->where('id', $client->id)->update([
        'user_id' => $client->owner_id,
        'redirect' => implode(',', $uris),
        'personal_access_client' => in_array('personal_access', $grants, true),
        'password_client' => in_array('password', $grants, true),
      ]);
    });

    Schema::table('oauth_clients', function (Blueprint $table) {
      $table->dropMorphs('owner');
      $table->dropColumn(['redirect_uris', 'grant_types']);
    });
  }
};
