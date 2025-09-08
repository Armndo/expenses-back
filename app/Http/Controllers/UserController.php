<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Token;

class UserController extends Controller
{
  public function login(Request $request) {$credentials = $request->only(["email", "password"]);

    if (!Auth::attempt($credentials)) {
      return response("error", 401);
    }

    $user = $request->user();
    // Token::where("user_id", $user->id)->update(["revoked" => true]); // TODO reimplement

    $createdToken = $user->createToken("Access Token");
    $token = $createdToken->token;
    $token->expires_at = Carbon::now()->addSeconds(3);
    $token->save();

    return [
      "token" => $createdToken->accessToken,
    ];
  }

  public function logout() {
    $user = Auth::user();
    Token::where("user_id", $user->id)->update(["revoked" => true]);

    return "ok";
  }

  public function info() {
    return "ok";
  }
}
