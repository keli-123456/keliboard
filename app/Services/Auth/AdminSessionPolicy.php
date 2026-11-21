<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

final class AdminSessionPolicy
{
    private const LIFETIME_MINUTES = 480;
    private const MAX_SESSIONS = 5;
    private const ABILITY = 'admin:session';

    public static function issue(User $user): NewAccessToken
    {
        return $user->getConnection()->transaction(function () use ($user) {
            // Serialize sign-ins for this account so concurrent requests cannot exceed the limit.
            $lockedUser = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            $now = now();
            $token = $lockedUser->createToken(
                Str::random(20),
                $lockedUser->is_admin ? ['*', self::ABILITY] : ['*'],
                $lockedUser->is_admin ? $now->copy()->addMinutes(self::LIFETIME_MINUTES) : $now->copy()->addYear(),
            );

            if ($lockedUser->is_admin) {
                $keep = $lockedUser->tokens()
                    ->whereJsonContains('abilities', self::ABILITY)
                    ->where('expires_at', '>', $now)
                    ->orderByDesc('id')->limit(self::MAX_SESSIONS)->pluck('id');
                $lockedUser->tokens()->whereNotIn('id', $keep)->delete();
            }

            return $token;
        }, 3);
    }

    public static function allows(PersonalAccessToken $token, User $user): bool
    {
        if (!$user->is_admin) {
            return true;
        }

        // Require a fresh login for older administrator tokens issued outside the session limit.
        return in_array(self::ABILITY, $token->abilities ?? [], true)
            && $token->expires_at !== null
            && $token->created_at?->gt(now()->subMinutes(self::LIFETIME_MINUTES));
    }
}
