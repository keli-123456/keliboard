<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Services\AuthService;
use Closure;
use Illuminate\Support\Facades\Auth;

class Staff
{
    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        if (!$authorization) throw new ApiException('未登录或登陆已过期', 403);

        $user = AuthService::findUserByBearerToken((string) $authorization);
        if (!$user || (!$user->is_staff && !$user->is_admin)) {
            throw new ApiException('未登录或登陆已过期', 403);
        }
        if ((bool) $user->banned) {
            throw new ApiException('账号已被封禁', 403);
        }

        Auth::setUser($user);
        $request->setUserResolver(fn () => $user);
        $request->merge([
            'user' => [
                'id' => (int) $user->id,
                'email' => (string) $user->email,
                'is_admin' => (bool) $user->is_admin,
                'is_staff' => (bool) $user->is_staff,
            ]
        ]);
        return $next($request);
    }
}
