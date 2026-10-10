<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Auth;
use Closure;
use App\Models\User;

class Admin
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
        /** @var User|null $user */
        $user = Auth::guard()->user();
        
        if (!$user) {
            throw new ApiException('Unauthorized', 401);
        }

        if (!$user->is_admin) {
            throw new ApiException('Unauthorized', 403);
        }
        
        return $next($request);
    }
}
