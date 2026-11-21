<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Auth\AdminSessionPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * 策略映射
     * @var array<class-string, class-string>
     */
    protected $policies = [
        // 'App\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * 注册任何认证/授权服务
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        Sanctum::authenticateAccessTokensUsing(static function (PersonalAccessToken $token, bool $valid): bool {
            if (!$valid) {
                return false;
            }

            $user = $token->tokenable;
            return !$user instanceof User || AdminSessionPolicy::allows($token, $user);
        });
    }
}
