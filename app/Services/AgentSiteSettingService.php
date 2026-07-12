<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\AgentSiteSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AgentSiteSettingService
{
    private const LANDING_THEMES = ['sakura', 'spark', 'blue_cat', 'detective', 'phantom'];

    private const CONFIG_FIELDS = [
        'site_name',
        'logo_url',
        'landing_theme',
        'accent_color',
        'support_name',
        'support_url',
        'customer_service_type',
        'customer_service_id',
        'announcement_title',
        'announcement',
        'seo_title',
        'seo_description',
    ];

    public function list(User $agent): array
    {
        $this->activeProfile($agent);

        return AgentSiteSetting::query()
            ->with('domain')
            ->where('agent_user_id', $agent->id)
            ->orderBy('setting_scope')
            ->orderBy('setting_key')
            ->orderBy('id')
            ->get()
            ->map(fn (AgentSiteSetting $setting): array => $this->payload($setting))
            ->values()
            ->all();
    }

    public function save(User $agent, array $payload): array
    {
        return DB::transaction(function () use ($agent, $payload): array {
            $this->activeProfile($agent, true);

            $id = (int) ($payload['id'] ?? 0);
            $setting = null;
            if (array_key_exists('id', $payload)) {
                $setting = AgentSiteSetting::query()
                    ->where('agent_user_id', $agent->id)
                    ->where('id', $id)
                    ->lockForUpdate()
                    ->first();

                if (!$setting) {
                    throw new ApiException('Agent site setting is not available');
                }
            }

            $domainId = $this->destinationDomainId($agent, $payload, $setting);
            $scope = $domainId === null ? AgentSiteSetting::SCOPE_DEFAULT : AgentSiteSetting::SCOPE_DOMAIN;
            $key = $domainId === null ? AgentSiteSetting::KEY_DEFAULT : (string) $domainId;

            if ($setting) {
                if ((string) $setting->setting_scope !== $scope || (string) $setting->setting_key !== $key) {
                    throw new ApiException('Agent site setting domain cannot be changed');
                }
            } else {
                $setting = AgentSiteSetting::query()
                    ->where('agent_user_id', $agent->id)
                    ->where('setting_scope', $scope)
                    ->where('setting_key', $key)
                    ->lockForUpdate()
                    ->first() ?? new AgentSiteSetting();
            }

            $values = $this->cleanPayload($payload, $setting);
            $now = time();

            $setting->agent_user_id = (int) $agent->id;
            $setting->agent_domain_id = $domainId;
            foreach ($values as $field => $value) {
                $setting->{$field} = $value;
            }
            if (!$setting->exists) {
                $setting->created_at = $now;
            }
            $setting->updated_at = $now;
            $setting->save();

            return $this->payload($setting->fresh('domain') ?: $setting);
        });
    }

    public function resolve(?array $context): array
    {
        $agentUserId = (int) ($context['agent_user_id'] ?? 0);
        if ($agentUserId <= 0) {
            return [];
        }

        $default = AgentSiteSetting::query()
            ->where('agent_user_id', $agentUserId)
            ->where('setting_scope', AgentSiteSetting::SCOPE_DEFAULT)
            ->where('setting_key', AgentSiteSetting::KEY_DEFAULT)
            ->where('enabled', true)
            ->first();

        $domain = null;
        $domainId = (int) ($context['agent_domain_id'] ?? 0);
        if ($domainId > 0) {
            $domain = AgentSiteSetting::query()
                ->where('agent_user_id', $agentUserId)
                ->where('agent_domain_id', $domainId)
                ->where('setting_scope', AgentSiteSetting::SCOPE_DOMAIN)
                ->where('setting_key', (string) $domainId)
                ->where('enabled', true)
                ->first();
        }

        if (!$domain && !$default) {
            return [];
        }

        if (!$domain) {
            return $this->payload($default);
        }

        $payload = $this->payload($domain);
        foreach (self::CONFIG_FIELDS as $field) {
            $domainValue = $payload[$field] ?? '';
            if ($this->isEmptyValue($domainValue) && $default) {
                $payload[$field] = $this->payloadValue($default->{$field});
            }
        }

        return $payload;
    }

    public function effective(User $agent, ?int $agentDomainId = null): array
    {
        $this->activeProfile($agent);

        $default = AgentSiteSetting::query()
            ->where('agent_user_id', $agent->id)
            ->where('setting_scope', AgentSiteSetting::SCOPE_DEFAULT)
            ->where('setting_key', AgentSiteSetting::KEY_DEFAULT)
            ->where('enabled', true)
            ->first();

        $agentDomain = null;
        $domain = null;
        if ($agentDomainId !== null) {
            $domainId = $this->agentDomainId($agent, $agentDomainId);
            $agentDomain = AgentDomain::query()->findOrFail($domainId);
            $domain = AgentSiteSetting::query()
                ->where('agent_user_id', $agent->id)
                ->where('agent_domain_id', $domainId)
                ->where('setting_scope', AgentSiteSetting::SCOPE_DOMAIN)
                ->where('setting_key', (string) $domainId)
                ->where('enabled', true)
                ->first();
        }

        $setting = null;
        if ($domain || $default) {
            $setting = $this->payload($domain ?: $default);
            foreach (self::CONFIG_FIELDS as $field) {
                $domainValue = $setting[$field] ?? '';
                if ($domain && $this->isEmptyValue($domainValue) && $default) {
                    $setting[$field] = $this->payloadValue($default->{$field});
                }
            }
        }

        $sources = [];
        foreach (self::CONFIG_FIELDS as $field) {
            if ($domain && !$this->isEmptyValue($domain->{$field})) {
                $sources[$field] = 'domain';
                continue;
            }

            $sources[$field] = $default && !$this->isEmptyValue($default->{$field})
                ? 'default'
                : 'empty';
        }

        return [
            'scope' => $agentDomain ? AgentSiteSetting::SCOPE_DOMAIN : AgentSiteSetting::SCOPE_DEFAULT,
            'domain' => $agentDomain ? [
                'id' => (int) $agentDomain->id,
                'domain' => (string) $agentDomain->domain,
                'status' => (string) $agentDomain->status,
            ] : null,
            'setting' => $setting,
            'sources' => $sources,
        ];
    }

    public function payload(AgentSiteSetting $setting): array
    {
        $payload = [
            'id' => (int) $setting->id,
            'agent_user_id' => (int) $setting->agent_user_id,
            'agent_domain_id' => $setting->agent_domain_id !== null ? (int) $setting->agent_domain_id : null,
            'enabled' => (bool) $setting->enabled,
            'setting_scope' => (string) $setting->setting_scope,
            'setting_key' => (string) $setting->setting_key,
            'site_name' => $this->payloadValue($setting->site_name),
            'logo_url' => $this->payloadValue($setting->logo_url),
            'landing_theme' => $this->payloadValue($setting->landing_theme),
            'accent_color' => $this->payloadValue($setting->accent_color),
            'support_name' => $this->payloadValue($setting->support_name),
            'support_url' => $this->payloadValue($setting->support_url),
            'customer_service_type' => $this->payloadValue($setting->customer_service_type),
            'customer_service_id' => $this->payloadValue($setting->customer_service_id),
            'announcement_title' => $this->payloadValue($setting->announcement_title),
            'announcement' => $this->payloadValue($setting->announcement),
            'seo_title' => $this->payloadValue($setting->seo_title),
            'seo_description' => $this->payloadValue($setting->seo_description),
            'created_at' => $this->timestampValue($setting->created_at),
            'updated_at' => $this->timestampValue($setting->updated_at),
        ];

        if ($setting->relationLoaded('domain') && $setting->domain) {
            $payload['domain'] = [
                'id' => (int) $setting->domain->id,
                'domain' => (string) $setting->domain->domain,
                'status' => (string) $setting->domain->status,
            ];
        }

        return $payload;
    }

    private function cleanPayload(array $payload, AgentSiteSetting $setting): array
    {
        $values = [];
        $fields = [
            'site_name' => ['string', 80],
            'logo_url' => ['url', 500],
            'landing_theme' => ['theme', 32],
            'accent_color' => ['color', 16],
            'support_name' => ['string', 80],
            'support_url' => ['url', 500],
            'customer_service_type' => ['customer_service_type', 32],
            'customer_service_id' => ['string', 255],
            'announcement_title' => ['string', 120],
            'announcement' => ['string', 500],
            'seo_title' => ['string', 120],
            'seo_description' => ['string', 255],
        ];

        foreach ($fields as $field => [$type, $max]) {
            if (!$setting->exists || array_key_exists($field, $payload)) {
                $values[$field] = $this->cleanField($payload[$field] ?? '', $type, $max);
            }
        }

        if (!$setting->exists || array_key_exists('enabled', $payload)) {
            $values['enabled'] = array_key_exists('enabled', $payload) ? (bool) $payload['enabled'] : true;
        }

        return $values;
    }

    private function cleanField(mixed $value, string $type, int $max): string
    {
        if ($type === 'url') {
            return $this->cleanUrl($value);
        }

        if ($type === 'theme') {
            return $this->cleanLandingTheme($value);
        }

        if ($type === 'color') {
            return $this->cleanColor($value);
        }

        if ($type === 'customer_service_type') {
            return $this->cleanCustomerServiceType($value);
        }

        return $this->cleanString($value, $max);
    }

    private function destinationDomainId(User $agent, array $payload, ?AgentSiteSetting $setting): ?int
    {
        if (array_key_exists('agent_domain_id', $payload)) {
            return $this->agentDomainId($agent, $payload['agent_domain_id'], true);
        }

        if ($setting) {
            return $setting->agent_domain_id !== null
                ? $this->agentDomainId($agent, $setting->agent_domain_id, true)
                : null;
        }

        return null;
    }

    private function agentDomainId(User $agent, mixed $value, bool $lock = false): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            $domainId = $value;
        } elseif (is_string($value) && preg_match('/^[1-9][0-9]*$/', trim($value))) {
            $domainId = (int) trim($value);
        } else {
            throw new ApiException('Agent domain is not available');
        }

        if ($domainId <= 0) {
            throw new ApiException('Agent domain is not available');
        }

        $query = AgentDomain::query()->where('id', $domainId);
        if ($lock) {
            $query->lockForUpdate();
        }

        $domain = $query->first();
        if (
            !$domain
            || (int) $domain->agent_user_id !== (int) $agent->id
            || (string) $domain->status !== AgentDomain::STATUS_ACTIVE
        ) {
            throw new ApiException('Agent domain is not available');
        }

        return $domainId;
    }

    private function activeProfile(User $agent, bool $lock = false): AgentProfile
    {
        $query = AgentProfile::query()
            ->where('user_id', $agent->id)
            ->where('status', AgentCenterService::STATUS_ACTIVE);

        if ($lock) {
            $query->lockForUpdate();
        }

        $profile = $query->first();
        if (!$profile) {
            throw new ApiException('Agent permission is not active');
        }

        return $profile;
    }

    private function cleanString(mixed $value, int $max): string
    {
        return mb_substr(trim(strip_tags((string) $value)), 0, $max);
    }

    private function cleanUrl(mixed $value): string
    {
        $url = trim(strip_tags((string) $value));
        if ($url === '') {
            return '';
        }

        if (mb_strlen($url) > 500) {
            throw new ApiException('URL format is invalid');
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new ApiException('URL format is invalid');
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new ApiException('URL scheme is not allowed');
        }

        return $url;
    }

    private function cleanColor(mixed $value): string
    {
        $color = $this->cleanString($value, 16);
        if ($color === '') {
            return '';
        }

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            throw new ApiException('Accent color is invalid');
        }

        return strtolower($color);
    }

    private function cleanLandingTheme(mixed $value): string
    {
        $theme = $this->cleanString($value, 32);
        if ($theme === '') {
            return '';
        }

        if (!in_array($theme, self::LANDING_THEMES, true)) {
            throw new ApiException('Landing theme is invalid');
        }

        return $theme;
    }

    private function cleanCustomerServiceType(mixed $value): string
    {
        $type = strtolower($this->cleanString($value, 32));
        if ($type === '') {
            return '';
        }

        if (!in_array($type, ['none', 'link', 'crisp', 'chatra'], true)) {
            throw new ApiException('Customer service type is invalid');
        }

        return $type;
    }

    private function isEmptyValue(mixed $value): bool
    {
        return trim((string) $value) === '';
    }

    private function payloadValue(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    private function timestampValue(mixed $value): ?int
    {
        if (!$value) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        return (int) $value;
    }
}
