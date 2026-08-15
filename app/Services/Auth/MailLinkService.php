<?php

namespace App\Services\Auth;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Services\NotificationSiteContextService;
use App\Services\SiteUserScopeService;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class MailLinkService
{
    /**
     * 处理邮件链接登录逻辑
     *
     * @param string $email 用户邮箱
     * @param string|null $redirect 重定向地址
     * @return array 返回处理结果
     */
    public function handleMailLink(string $email, ?string $redirect = null, ?Request $request = null): array
    {
        if (!(int) admin_setting('login_with_mail_link_enable')) {
            return [false, [404, null]];
        }

        $siteScope = app(SiteUserScopeService::class);
        $lastSendKey = $siteScope->cacheKey('LAST_SEND_LOGIN_WITH_MAIL_LINK_TIMESTAMP', $email, $request);
        if (Cache::get($lastSendKey)) {
            return [false, [429, __('Sending frequently, please try again later')]];
        }

        $user = $siteScope->findAuthenticatableUserByEmail($email, $request);
        if (!$user) {
            return [true, true]; // 成功但用户不存在，保护用户隐私
        }

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user->id, 300);
        Cache::put($lastSendKey, time(), 60);

        $notificationContext = app(NotificationSiteContextService::class)->forUser($user, $request);
        $redirectUrl = app(LoginRedirectService::class)->buildLoginFragment($code, $redirect);
        $baseUrl = rtrim((string) ($notificationContext['app_url'] ?: admin_setting('app_url')), '/');
        $link = $baseUrl !== '' ? $baseUrl . $redirectUrl : url($redirectUrl);

        $this->sendMailLinkEmail($user, $link, $notificationContext);

        return [true, true];
    }

    /**
     * 发送邮件链接登录邮件
     *
     * @param User $user 用户对象
     * @param string $link 登录链接
     * @return void
     */
    private function sendMailLinkEmail(User $user, string $link, array $notificationContext): void
    {
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('Login to :name', [
                'name' => $notificationContext['app_name']
            ]),
            'template_name' => 'login',
            'template_value' => app(NotificationSiteContextService::class)->templateValues($notificationContext, [
                'link' => $link,
            ]),
            'dispatch_context' => app(NotificationSiteContextService::class)->dispatchContext($notificationContext),
        ]);
    }

    /**
     * 处理Token登录
     * 
     * @param string $token 登录令牌
     * @return int|null 用户ID或null
     */
    public function handleTokenLogin(string $token): ?int
    {
        $key = CacheKey::get('TEMP_TOKEN', $token);
        $userId = Cache::get($key);

        if (!$userId) {
            return null;
        }

        $user = User::find($userId);

        if (!$user || $user->banned) {
            return null;
        }

        Cache::forget($key);

        return $userId;
    }
}
