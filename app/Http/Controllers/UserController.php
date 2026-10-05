<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;

class UserController extends Controller
{
  // Token lifetime per client. Unknown or missing "device" falls back to the web one.
  private const DEVICES = [
    "web" => 1,
    "android" => 90,
  ];

  public function login(Request $request) {
    $credentials = $request->only(["email", "password"]);

    if (!Auth::attempt($credentials)) {
      return response("error", 401);
    }

    $device = $request->input("device");
    $device = array_key_exists($device, self::DEVICES) ? $device : "web";

    $user = $request->user();

    // Passport bakes the TTL into the AuthorizationServer singleton the first time it is
    // resolved, so drop it to make it pick up this device's lifetime.
    Passport::personalAccessTokensExpireIn(Carbon::now()->addDays(self::DEVICES[$device]));
    app()->forgetInstance(AuthorizationServer::class);
    $createdToken = $user->createToken($device);

    return [
      "token" => $createdToken->accessToken,
    ];
  }

  public function logout() {
    // Only the token used in this request, so other devices stay logged in.
    Auth::user()->token()->revoke();

    return "ok";
  }

  public function info() {
    return "ok";
  }
}
