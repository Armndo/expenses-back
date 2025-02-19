<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Passport\Token;

class UserController extends Controller
{
    public function login(Request $request) {$credentials = $request->only(["email", "password"]);

        if (!Auth::attempt($credentials)) {
            return response("error", 401);
        }

        $user = $request->user();
        Token::where("user_id", $user->id)->update(["revoked" => true]);

        $createdToken = $user->createToken("Access Token");
        $token = $createdToken->token;
        $token->expires_at = Carbon::now()->addHour();
        $token->save();

        return [
            "token" => $createdToken->accessToken,
        ];
    }

    public function info(Request $request) {
        return Auth::user();
    }
}
