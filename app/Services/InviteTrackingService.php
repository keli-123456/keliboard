<?php

namespace App\Services;

use App\Models\InviteClick;
use App\Models\InviteCode;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class InviteTrackingService
{
    private const DEDUPLICATION_SECONDS = 1800;
    private const ATTRIBUTION_SECONDS = 2592000;

    public function track(Request $request): array
    {
        if (!$this->tableAvailable()) {
            return ['tracked' => false];
        }

        $code = trim((string) $request->input('code', ''));
        if ($code === '') {
            return ['tracked' => false];
        }

        $inviteCode = InviteCode::query()->where('code', $code)->first();
        if (!$inviteCode) {
            return ['tracked' => false];
        }

        $now = time();
        $visitorHash = $this->visitorHash($request);
        $context = app(SiteContextService::class)->resolve($request);
        $siteId = !empty($context['site_id']) ? (int) $context['site_id'] : null;
        $source = $this->resolveSource($request);
        $referrerHost = $this->normalizeHost((string) $request->input('referrer', ''));
        $landingHost = $this->normalizeHost((string) $request->getHost());

        $recent = InviteClick::query()
            ->where('invite_code_id', (int) $inviteCode->id)
            ->where('visitor_hash', $visitorHash)
            ->where('last_clicked_at', '>=', $now - self::DEDUPLICATION_SECONDS)
            ->latest('last_clicked_at')
            ->first();

        if ($recent) {
            $recent->hit_count = max(1, (int) $recent->hit_count) + 1;
            $recent->last_clicked_at = $now;
            $recent->source = $source;
            $recent->referrer_host = $referrerHost;
            $recent->landing_host = $landingHost;
            $recent->utm_medium = $this->cleanLabel($request->input('utm_medium'), 80);
            $recent->utm_campaign = $this->cleanLabel($request->input('utm_campaign'), 120);
            $recent->save();

            return ['tracked' => true];
        }

        InviteClick::query()->create([
            'invite_code_id' => (int) $inviteCode->id,
            'invite_code' => (string) $inviteCode->code,
            'inviter_user_id' => (int) $inviteCode->user_id,
            'site_id' => $siteId,
            'visitor_hash' => $visitorHash,
            'source' => $source,
            'referrer_host' => $referrerHost,
            'landing_host' => $landingHost,
            'utm_medium' => $this->cleanLabel($request->input('utm_medium'), 80),
            'utm_campaign' => $this->cleanLabel($request->input('utm_campaign'), 120),
            'hit_count' => 1,
            'clicked_at' => $now,
            'last_clicked_at' => $now,
        ]);

        return ['tracked' => true];
    }

    public function attributeRegistration(Request $request, string $code, User $user): void
    {
        if (!$this->tableAvailable()) {
            return;
        }

        $inviteCode = InviteCode::query()->where('code', $code)->first();
        if (!$inviteCode) {
            return;
        }

        $click = InviteClick::query()
            ->where('invite_code_id', (int) $inviteCode->id)
            ->where('visitor_hash', $this->visitorHash($request))
            ->whereNull('registered_user_id')
            ->where('last_clicked_at', '>=', time() - self::ATTRIBUTION_SECONDS)
            ->latest('last_clicked_at')
            ->first();

        if (!$click) {
            return;
        }

        $click->registered_user_id = (int) $user->id;
        $click->converted_at = time();
        $click->save();
    }

    public function visitorHash(Request $request): string
    {
        $fingerprint = implode('|', [
            strtolower(trim((string) $request->ip())),
            strtolower(trim((string) $request->userAgent())),
            strtolower(trim((string) $request->header('Accept-Language', ''))),
        ]);

        return hash_hmac('sha256', $fingerprint, (string) config('app.key', 'keliboard'));
    }

    private function resolveSource(Request $request): string
    {
        $explicit = $this->cleanSlug($request->input('utm_source'));
        if ($explicit !== null) {
            return 'utm:' . $explicit;
        }

        $referrerHost = $this->normalizeHost((string) $request->input('referrer', ''));
        $userAgent = strtolower((string) $request->userAgent());
        $haystack = strtolower((string) $referrerHost . ' ' . $userAgent);

        foreach ([
            'wechat' => ['micromessenger', 'weixin.qq.com'],
            'telegram' => ['telegram', 't.me'],
            'qq' => ['qq/', 'mqqbrowser', 'qzone.qq.com'],
            'weibo' => ['weibo'],
            'facebook' => ['facebook.com', 'fb.com', 'messenger'],
            'x' => ['twitter.com', 'x.com'],
            'discord' => ['discord.com', 'discordapp.com'],
        ] as $source => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $source;
                }
            }
        }

        return $referrerHost ? 'referral' : 'direct';
    }

    private function normalizeHost(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $host = parse_url(str_contains($value, '://') ? $value : 'https://' . $value, PHP_URL_HOST);
        $host = strtolower(trim((string) $host));

        return $host !== '' ? Str::limit($host, 191, '') : null;
    }

    private function cleanLabel(mixed $value, int $limit): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value !== '' ? Str::limit($value, $limit, '') : null;
    }

    private function cleanSlug(mixed $value): ?string
    {
        $value = strtolower(trim((string) ($value ?? '')));
        if ($value === '') {
            return null;
        }

        $value = preg_replace('/[^a-z0-9._-]+/', '-', $value) ?: '';
        $value = trim($value, '-');

        return $value !== '' ? Str::limit($value, 40, '') : null;
    }

    private function tableAvailable(): bool
    {
        try {
            return Schema::hasTable('v2_invite_click');
        } catch (\Throwable) {
            return false;
        }
    }
}
