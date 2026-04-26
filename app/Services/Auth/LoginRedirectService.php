<?php

namespace App\Services\Auth;

class LoginRedirectService
{
    public function normalize(?string $redirect): string
    {
        $raw = trim((string) $redirect);
        if ($raw === '') {
            return 'dashboard';
        }

        $raw = str_replace('\\', '/', $raw);
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $raw) || str_starts_with($raw, '//')) {
            return 'dashboard';
        }

        $raw = ltrim($raw, '/');
        $raw = preg_replace('/\/{2,}/', '/', $raw) ?: 'dashboard';

        return $raw !== '' ? $raw : 'dashboard';
    }

    public function buildLoginFragment(string $verify, ?string $redirect = null): string
    {
        return '/#/login?verify=' . rawurlencode($verify)
            . '&redirect=' . rawurlencode($this->normalize($redirect));
    }
}
