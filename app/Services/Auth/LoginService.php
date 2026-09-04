<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\RiskEventService;
use App\Services\SiteUserScopeService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class LoginService
{
    /**
     * 处理用户登录
     *
     * @param string $email 用户邮箱
     * @param string $password 用户密码
     * @return array [成功状态, 用户对象或错误信息]
     */
    public function login(string $email, string $password, ?Request $request = null): array
    {
        $requestIp = null;
        $requestUa = null;
        $baseMeta = [];
        $siteScope = app(SiteUserScopeService::class);
        $req = $request;
        try {
            $req = $req ?: request();
            $requestIp = $req->getClientIp();
            $requestUa = $req->userAgent();

            $network = [
                'remote_addr' => $req->server('REMOTE_ADDR'),
                'x_forwarded_for' => $req->header('X-Forwarded-For'),
                'x_real_ip' => $req->header('X-Real-IP'),
                'forwarded' => $req->header('Forwarded'),
                'cf_connecting_ip' => $req->header('CF-Connecting-IP'),
            ];
            $network = array_filter($network, fn($v) => $v !== null && $v !== '');
            if ($network) {
                $baseMeta = ['network' => $network];
            }
        } catch (\Throwable $ignored) {
            // ignore
        }

        $siteContext = $siteScope->context($req);
        if (!empty($siteContext['enabled'])) {
            $baseMeta['site'] = [
                'site_id' => $siteContext['site_id'] ?? null,
                'source' => $siteContext['source'] ?? null,
            ];
        }
        $loginIpLimitKey = $this->loginIpLimitKey($siteScope, $requestIp, $req);
        if ($loginIpLimitKey !== null) {
            $loginIpLimitCount = max(1, (int) admin_setting('login_ip_limit_count', 60));
            if (RateLimiter::tooManyAttempts($loginIpLimitKey, $loginIpLimitCount)) {
                RiskEventService::record('login_failed', [
                    'ip' => $requestIp,
                    'ua' => $requestUa,
                    'status_code' => 429,
                    'meta' => array_merge([
                        'email' => $email,
                        'reason' => 'ip_rate_limit',
                        'count' => RateLimiter::attempts($loginIpLimitKey),
                    ], $baseMeta),
                ]);

                return [false, [429, __('Too many login attempts. Please try again later.')]];
            }
        }

        $passwordErrorLimitKey = $siteScope->cacheKey(
            'PASSWORD_ERROR_LIMIT',
            $this->passwordLimitIdentity($email, $requestIp),
            $req
        );

        // 检查密码错误限制
        if ((int) admin_setting('password_limit_enable', true)) {
            $passwordErrorCount = (int) Cache::get($passwordErrorLimitKey, 0);
            if ($passwordErrorCount >= (int) admin_setting('password_limit_count', 5)) {
                $this->recordLoginIpFailure($loginIpLimitKey);
                RiskEventService::record('login_failed', [
                    'ip' => $requestIp,
                    'ua' => $requestUa,
                    'status_code' => 429,
                    'meta' => array_merge([
                        'email' => $email,
                        'reason' => 'password_error_limit',
                        'count' => $passwordErrorCount,
                    ], $baseMeta),
                ]);
                return [
                    false,
                    [
                        429,
                        __('There are too many password errors, please try again after :minute minutes.', [
                            'minute' => admin_setting('password_limit_expire', 60)
                        ])
                    ]
                ];
            }
        }

        // 查找用户
        $user = $siteScope->findAuthenticatableUserByEmail($email, $req);
        if (!$user) {
            $this->recordLoginIpFailure($loginIpLimitKey);
            RiskEventService::record('login_failed', [
                'ip' => $requestIp,
                'ua' => $requestUa,
                'status_code' => 400,
                'meta' => array_merge([
                    'email' => $email,
                    'reason' => 'user_not_found',
                ], $baseMeta),
            ]);
            return [false, [400, __('Incorrect email or password')]];
        }

        // 验证密码
        if (
            !Helper::multiPasswordVerify(
                $user->password_algo,
                $user->password_salt,
                $password,
                $user->password
            )
        ) {
            $this->recordLoginIpFailure($loginIpLimitKey);
            RiskEventService::record('login_failed', [
                'user_id' => $user->id,
                'ip' => $requestIp,
                'ua' => $requestUa,
                'status_code' => 400,
                'meta' => array_merge([
                    'email' => $email,
                    'reason' => 'invalid_password',
                ], $baseMeta),
            ]);
            // 增加密码错误计数
            if ((int) admin_setting('password_limit_enable', true)) {
                $passwordErrorCount = (int) Cache::get($passwordErrorLimitKey, 0);
                Cache::put(
                    $passwordErrorLimitKey,
                    (int) $passwordErrorCount + 1,
                    60 * (int) admin_setting('password_limit_expire', 60)
                );
            }
            return [false, [400, __('Incorrect email or password')]];
        }

        // 检查账户状态
        if ($user->banned) {
            $this->recordLoginIpFailure($loginIpLimitKey);
            RiskEventService::record('login_failed', [
                'user_id' => $user->id,
                'ip' => $requestIp,
                'ua' => $requestUa,
                'status_code' => 400,
                'meta' => array_merge([
                    'email' => $email,
                    'reason' => 'banned',
                ], $baseMeta),
            ]);
            return [false, [400, __('Your account has been suspended')]];
        }

        Cache::forget($passwordErrorLimitKey);

        // 更新最后登录时间
        $user->last_login_at = time();
        $user->save();

        RiskEventService::record('login_success', [
            'user_id' => $user->id,
            'ip' => $requestIp,
            'ua' => $requestUa,
            'status_code' => 200,
            'meta' => array_merge([
                'email' => $email,
                'is_admin' => (bool) $user->is_admin,
            ], $baseMeta),
        ]);

        HookManager::call('user.login.after', $user);
        return [true, $user];
    }

    /**
     * 处理密码重置
     *
     * @param string $email 用户邮箱
     * @param string $emailCode 邮箱验证码
     * @param string $password 新密码
     * @return array [成功状态, 结果或错误信息]
     */
    public function resetPassword(string $email, string $emailCode, string $password, ?Request $request = null): array
    {
        $siteScope = app(SiteUserScopeService::class);
        $req = $request ?: $this->currentRequest();

        // 检查重置请求限制
        $forgetRequestLimitKey = $siteScope->cacheKey('FORGET_REQUEST_LIMIT', $email, $req);
        $forgetRequestLimit = (int) Cache::get($forgetRequestLimitKey);
        if ($forgetRequestLimit >= 3) {
            return [false, [429, __('Reset failed, Please try again later')]];
        }

        // 验证邮箱验证码
        $cachedEmailCode = Cache::get($siteScope->cacheKey('EMAIL_VERIFY_CODE', $email, $req));
        if (
            preg_match('/^\d{6}$/', $emailCode) !== 1
            || !is_scalar($cachedEmailCode)
            || !hash_equals((string) $cachedEmailCode, $emailCode)
        ) {
            Cache::put($forgetRequestLimitKey, $forgetRequestLimit ? $forgetRequestLimit + 1 : 1, 300);
            return [false, [400, __('Incorrect email verification code')]];
        }

        // 查找用户
        $user = $siteScope->findAuthenticatableUserByEmail($email, $req);
        if (!$user) {
            return [false, [400, __('This email is not registered in the system')]];
        }

        // 更新密码
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->password_algo = NULL;
        $user->password_salt = NULL;

        if (!$user->save()) {
            return [false, [500, __('Reset failed')]];
        }

        HookManager::call('user.password.reset.after', $user);

        // 清除邮箱验证码
        Cache::forget($siteScope->cacheKey('EMAIL_VERIFY_CODE', $email, $req));

        return [true, true];
    }

    private function currentRequest(): ?Request
    {
        try {
            return request();
        } catch (\Throwable) {
            return null;
        }
    }

    private function loginIpLimitKey(
        SiteUserScopeService $siteScope,
        ?string $requestIp,
        ?Request $request
    ): ?string
    {
        $requestIp = trim((string) $requestIp);
        if ($requestIp === '') {
            return null;
        }

        return $siteScope->cacheKey(
            'LOGIN_IP_LIMIT',
            'ip:' . hash('sha256', $requestIp),
            $request
        );
    }

    private function passwordLimitIdentity(string $email, ?string $requestIp): string
    {
        $normalizedEmail = strtolower(trim($email));
        $normalizedIp = trim((string) $requestIp);

        return $normalizedEmail . ':ip:' . hash('sha256', $normalizedIp !== '' ? $normalizedIp : 'unknown');
    }

    private function recordLoginIpFailure(?string $loginIpLimitKey): void
    {
        if ($loginIpLimitKey === null) {
            return;
        }

        RateLimiter::hit(
            $loginIpLimitKey,
            max(1, (int) admin_setting('login_ip_limit_expire_seconds', 60))
        );
    }

    /**
     * 生成临时登录令牌和快速登录URL
     *
     * @param User $user 用户对象
     * @param string $redirect 重定向路径
     * @return string|null 快速登录URL
     */
    public function generateQuickLoginUrl(User $user, ?string $redirect = null): ?string
    {
        if (!$user || !$user->exists) {
            return null;
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);

        Cache::put($key, $user->id, 60);

        $loginRedirect = app(LoginRedirectService::class)->buildLoginFragment($code, $redirect);

        if (admin_setting('app_url')) {
            $url = admin_setting('app_url') . $loginRedirect;
        } else {
            $url = url($loginRedirect);
        }

        return $url;
    }
}
