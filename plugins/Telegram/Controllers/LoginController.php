<?php

namespace Plugin\Telegram\Controllers;

use App\Models\Plugin;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class LoginController extends \App\Http\Controllers\Controller
{
    private const PLUGIN_CODE = 'telegram';
    private const LOGIN_SESSION_PREFIX = 'tg_login:session:';

    public function login(Request $request)
    {
        $config = $this->getPluginConfig();
        $loginEnabled = $this->normalizeBool($config['enable_login'] ?? true);
        if (!$loginEnabled) {
            return $this->fail([404001, '没有找到该页面']);
        }

        $botToken = trim((string) admin_setting('telegram_bot_token', ''));
        if ($botToken === '') {
            return $this->fail([400200, 'Telegram 未配置']);
        }

        $timeoutSeconds = (int) ($config['login_auth_timeout'] ?? 300);
        if ($timeoutSeconds <= 0) $timeoutSeconds = 300;

        $params = $request->validate([
            'id' => ['required', 'integer'],
            'auth_date' => ['required', 'integer'],
            'hash' => ['required', 'string'],
            'first_name' => ['nullable', 'string'],
            'last_name' => ['nullable', 'string'],
            'username' => ['nullable', 'string'],
            'photo_url' => ['nullable', 'string'],
        ]);

        $authDate = (int) $params['auth_date'];
        if ($authDate <= 0 || abs(time() - $authDate) > $timeoutSeconds) {
            return $this->fail([400200, 'Telegram 登录已过期，请重试']);
        }

        if (!$this->verifyTelegramLoginPayload($params, $botToken)) {
            return $this->fail([400200, 'Telegram 登录校验失败']);
        }

        $telegramId = (int) $params['id'];
        $user = User::where('telegram_id', $telegramId)->first();
        if (!$user) {
            return $this->fail([400200, '该 Telegram 账号未绑定']);
        }
        if ((bool) $user->banned) {
            return $this->fail([403001, '账号已被封禁']);
        }

        $authService = new AuthService($user);
        return $this->success($authService->generateAuthData());
    }

    public function start(Request $request)
    {
        $config = $this->getPluginConfig();
        $loginEnabled = $this->normalizeBool($config['enable_login'] ?? true);
        if (!$loginEnabled) {
            return $this->fail([404001, '没有找到该页面']);
        }

        $botEnabled = (int) admin_setting('telegram_bot_enable', 0) ? 1 : 0;
        $botToken = trim((string) admin_setting('telegram_bot_token', ''));
        if (!$botEnabled || $botToken === '') {
            return $this->fail([400200, 'Telegram 未配置']);
        }

        $timeoutSeconds = $this->normalizeLoginTimeoutSeconds($config);

        $session = $this->generateSessionId();
        $secret = Str::random(32);

        Cache::put($this->getLoginSessionCacheKey($session), [
            'secret' => $secret,
            'created_at' => time(),
            'expires_at' => time() + $timeoutSeconds,
            'approved' => false,
            'approved_at' => null,
            'user_id' => null,
            'site' => $request->getSchemeAndHttpHost(),
        ], $timeoutSeconds);

        return $this->success([
            'session' => $session,
            'secret' => $secret,
            'start_param' => 'login_' . $session,
            'expires_in' => $timeoutSeconds,
        ]);
    }

    public function poll(Request $request)
    {
        $config = $this->getPluginConfig();
        $loginEnabled = $this->normalizeBool($config['enable_login'] ?? true);
        if (!$loginEnabled) {
            return $this->fail([404001, '没有找到该页面']);
        }

        $params = $request->validate([
            'session' => ['required', 'string'],
            'secret' => ['required', 'string'],
        ]);

        $session = trim((string) $params['session']);
        $secret = trim((string) $params['secret']);
        if (!$this->isValidSessionId($session) || $secret === '') {
            return $this->success(['status' => 'invalid']);
        }

        $cacheKey = $this->getLoginSessionCacheKey($session);
        $record = Cache::get($cacheKey);
        if (!is_array($record)) {
            return $this->success(['status' => 'expired']);
        }

        $expected = (string) ($record['secret'] ?? '');
        if ($expected === '' || !hash_equals($expected, $secret)) {
            return $this->success(['status' => 'invalid']);
        }

        if (!($record['approved'] ?? false)) {
            return $this->success(['status' => 'pending']);
        }

        $userId = (int) ($record['user_id'] ?? 0);
        if ($userId <= 0) {
            return $this->success(['status' => 'pending']);
        }

        $user = User::where('id', $userId)->first();
        if (!$user) {
            Cache::forget($cacheKey);
            return $this->success(['status' => 'expired']);
        }
        if ((bool) $user->banned) {
            Cache::forget($cacheKey);
            return $this->success(['status' => 'banned']);
        }

        Cache::forget($cacheKey);
        $authService = new AuthService($user);
        return $this->success([
            'status' => 'approved',
            ...$authService->generateAuthData(),
        ]);
    }

    private function getPluginConfig(): array
    {
        $raw = Plugin::where('code', self::PLUGIN_CODE)
            ->where('is_enabled', true)
            ->value('config');

        if (!$raw) return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeBool($value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }

    private function normalizeLoginTimeoutSeconds(array $config): int
    {
        $timeoutSeconds = (int) ($config['login_auth_timeout'] ?? 300);
        if ($timeoutSeconds <= 0) $timeoutSeconds = 300;
        return $timeoutSeconds;
    }

    private function generateSessionId(): string
    {
        // Telegram /start payload has a short length limit; keep it compact.
        for ($i = 0; $i < 5; $i += 1) {
            $session = Str::random(24);
            if ($this->isValidSessionId($session)) return $session;
        }
        return Str::random(24);
    }

    private function isValidSessionId(string $value): bool
    {
        $raw = trim($value);
        if ($raw === '') return false;
        if (strlen($raw) < 16 || strlen($raw) > 48) return false;
        return (bool) preg_match('/^[a-zA-Z0-9_-]+$/', $raw);
    }

    private function getLoginSessionCacheKey(string $session): string
    {
        return self::LOGIN_SESSION_PREFIX . $session;
    }

    private function verifyTelegramLoginPayload(array $payload, string $botToken): bool
    {
        $hash = (string) ($payload['hash'] ?? '');
        if ($hash === '') return false;

        $data = $payload;
        unset($data['hash']);

        ksort($data);
        $pairs = [];
        foreach ($data as $key => $value) {
            if ($value === null) continue;
            $pairs[] = $key . '=' . $value;
        }
        $dataCheckString = implode("\n", $pairs);

        $secretKey = hash('sha256', $botToken, true);
        $calculated = hash_hmac('sha256', $dataCheckString, $secretKey);
        return hash_equals($calculated, $hash);
    }
}
