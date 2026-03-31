<?php

namespace App\Http\Controllers\V1\User;

use App\Helpers\ResponseEnum;
use App\Http\Controllers\Controller;
use App\Http\Resources\NodeResource;
use App\Models\User;
use App\Services\ServerService;
use App\Support\UserClientCompatibilityService;
use App\Services\UserService;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    public function fetch(Request $request, UserClientCompatibilityService $compatibilityService)
    {
        $user = User::find($request->user()->id);
        $servers = [];
        $userService = new UserService();
        if ($userService->isAvailable($user)) {
            $servers = ServerService::getAvailableServers($user);
        }
        $eTag = sha1(json_encode(array_column($servers, 'cache_key')));
        if (strpos($request->header('If-None-Match', ''), $eTag) !== false ) {
            return response(null,304);
        }

        [$code, $message] = ResponseEnum::HTTP_OK;
        $data = NodeResource::collection($servers)->resolve($request);

        return response()->json([
            'status' => 'success',
            'message' => $message,
            'data' => $data,
            'compatibility' => $compatibilityService->summarize($servers),
            'error' => null,
        ], (int) substr(((string) $code), 0, 3))->header('ETag', "\"{$eTag}\"");
    }
}
