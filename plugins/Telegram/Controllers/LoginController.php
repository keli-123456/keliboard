<?php

namespace Plugin\Telegram\Controllers;

use App\Models\Plugin;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\Request;

class LoginController extends \App\Http\Controllers\Controller
{
    private const PLUGIN_CODE = 'telegram';

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

