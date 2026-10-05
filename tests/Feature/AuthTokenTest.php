<?php

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Passport\Token;

// Runs against a local Postgres DB with a personal access client already created:
//   DB_CONNECTION=pgsql_local DB_DATABASE_LOCAL=expenses_test ./vendor/bin/pest tests/Feature/AuthTokenTest.php
uses(DatabaseTransactions::class);

beforeEach(function () {
  if (DB::connection()->getDatabaseName() !== "expenses_test") {
    $this->markTestSkipped("Set DB_DATABASE_LOCAL=expenses_test (see top of file).");
  }

  $this->user = User::factory()->create(["username" => fake()->unique()->userName(), "lastname" => fake()->lastName(), "password" => "password"]);
});

function loginAs($test, ?string $device = null) {
  $body = ["email" => $test->user->email, "password" => "password"];
  if ($device) $body["device"] = $device;

  return $test->postJson("/login", $body)->assertOk()->json("token");
}

function tokenRow(string $jwt): Token {
  $id = json_decode(base64_decode(explode(".", $jwt)[1]), true)["jti"];

  return Token::find($id);
}

it("rejects bad credentials", function () {
  $this->postJson("/login", ["email" => $this->user->email, "password" => "nope"])
    ->assertStatus(401);
});

it("gives android tokens ~90 days and web tokens ~1 day", function () {
  $android = tokenRow(loginAs($this, "android"));
  $web = tokenRow(loginAs($this, "web"));
  $default = tokenRow(loginAs($this));
  $unknown = tokenRow(loginAs($this, "toaster"));

  expect($android->name)->toBe("android");
  expect(now()->diffInDays($android->expires_at, true))->toBeGreaterThan(89)->toBeLessThanOrEqual(90);

  foreach ([$web, $default, $unknown] as $token) {
    expect($token->name)->toBe("web");
    expect(now()->diffInHours($token->expires_at, true))->toBeGreaterThan(23)->toBeLessThanOrEqual(24);
  }
});

it("accepts the issued token on protected routes", function () {
  $jwt = loginAs($this, "android");

  $this->withToken($jwt)->getJson("/info")->assertOk();
});

it("logout revokes only the current token", function () {
  $android = loginAs($this, "android");
  $web = loginAs($this, "web");

  $this->withToken($android)->postJson("/logout")->assertOk();

  expect(tokenRow($android)->revoked)->toBeTrue();
  expect(tokenRow($web)->revoked)->toBeFalse();

  // Passport caches the resolved user per request, so reset the guard between calls.
  $this->app["auth"]->forgetGuards();

  $this->withToken($android)->getJson("/info")->assertUnauthorized();
  $this->app["auth"]->forgetGuards();
  $this->withToken($web)->getJson("/info")->assertOk();
});
