<?php

use App\Models\User;
use Laravel\Passport\Passport;

it("answers /info with ok to a logged in user", function () {
  Passport::actingAs(User::factory()->create());

  expect($this->getJson("/info")->assertOk()->getContent())->toBe("ok");
});

it("requires a token on every route but /login", function (string $method, string $uri) {
  $this->json($method, $uri)->assertUnauthorized();
})->with([
  ["GET", "/info"],
  ["POST", "/logout"],
  ["GET", "/data"],
  ["POST", "/expenses"],
  ["PUT", "/expenses/1"],
  ["DELETE", "/expenses/1"],
  ["POST", "/incomes"],
  ["PUT", "/incomes/1"],
  ["DELETE", "/incomes/1"],
]);

it("rejects a login with a wrong password or an unknown email", function (string $email, string $password) {
  $user = User::factory()->create();

  $this->postJson("/login", ["email" => $email === "known" ? $user->email : "nobody@example.com", "password" => $password])
    ->assertUnauthorized();
})->with([
  "wrong password" => ["known", "wrong"],
  "unknown email" => ["unknown", "password"],
]);

it("logs in with the right credentials", function () {
  $user = User::factory()->create();

  $this->postJson("/login", ["email" => $user->email, "password" => "password"])->assertOk()->assertJsonStructure(["token"]);
});
