<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class SessionController extends Controller
{
    public function logout(Request $request): Response
    {
        $user = $request->user();
        $token = $user->currentAccessToken();
        if (!$request->bearerToken() || !$token instanceof PersonalAccessToken) {
            throw new ApiException('A bearer session is required', 409);
        }

        $user->tokens()->whereKey($token->getKey())->delete();

        return new Response(null, 204);
    }
}
