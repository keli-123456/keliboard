<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Services\CaptchaService;
use App\Services\NotificationSiteContextService;
use App\Services\SiteUserScopeService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CommController extends Controller
{

    public function sendEmailVerify(CommSendEmailVerify $request)
    {
                // 验证人机验证码
        $captchaService = app(CaptchaService::class);
        [$captchaValid, $captchaError] = $captchaService->verify($request);
        if (!$captchaValid) {
            return $this->fail($captchaError);
        }

        $email = $request->input('email');

        // 检查白名单后缀限制
        if ((int) admin_setting('email_whitelist_enable', 0)) {
            $isRegisteredEmail = app(SiteUserScopeService::class)
                ->findAuthenticatableUserByEmail($email, $request) !== null;
            if (!$isRegisteredEmail) {
                $allowedSuffixes = Helper::getEmailSuffix();
                $emailSuffix = substr(strrchr($email, '@'), 1);

                if (!in_array($emailSuffix, $allowedSuffixes)) {
                    return $this->fail([400, __('Email suffix is not in whitelist')]);
                }
            }
        }

        $siteScope = app(SiteUserScopeService::class);
        $lastSendKey = $siteScope->cacheKey('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $email, $request);
        if (Cache::get($lastSendKey)) {
            return $this->fail([400, __('Email verification code has been sent, please request again later')]);
        }
        $code = random_int(100000, 999999);
        $notificationContext = app(NotificationSiteContextService::class)->forRequest($request);
        $subject = $notificationContext['app_name'] . __('Email verification code');

        SendEmailJob::dispatch([
            'email' => $email,
            'subject' => $subject,
            'template_name' => 'verify',
            'template_value' => app(NotificationSiteContextService::class)->templateValues($notificationContext, [
                'code' => $code,
            ]),
            'dispatch_context' => app(NotificationSiteContextService::class)->dispatchContext($notificationContext),
        ]);

        Cache::put($siteScope->cacheKey('EMAIL_VERIFY_CODE', $email, $request), $code, 300);
        Cache::put($lastSendKey, time(), 60);
        return $this->success(true);
    }

    public function pv(Request $request)
    {
        $inviteCode = InviteCode::where('code', $request->input('invite_code'))->first();
        if ($inviteCode) {
            $inviteCode->pv = $inviteCode->pv + 1;
            $inviteCode->save();
        }

        return $this->success(true);
    }

}
