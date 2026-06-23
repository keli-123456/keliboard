<?php

namespace App\Services;

use Illuminate\Http\Request;

class AgentPublicConfigService
{
    public function apply(array $data, Request $request): array
    {
        $context = app(AgentCommerceContextResolver::class)->resolveRequest($request);
        if (!$context) {
            return $data;
        }

        $data['agent_context'] = $this->contextPayload($context);

        $setting = app(AgentSiteSettingService::class)->resolve($context);
        if (!$setting) {
            return $data;
        }

        $siteName = $this->stringValue($setting['site_name'] ?? '');
        if ($siteName !== '') {
            $data['app_name'] = $siteName;
        }

        $logoUrl = $this->stringValue($setting['logo_url'] ?? '');
        if ($logoUrl !== '') {
            $data['logo'] = $logoUrl;
        }

        $landingTheme = $this->stringValue($setting['landing_theme'] ?? '');
        if ($landingTheme !== '') {
            $data['landing_theme'] = $landingTheme;
        } else {
            $data['landing_theme'] = $data['landing_theme'] ?? null;
        }

        $data['agent_announcement_title'] = $this->stringValue($setting['announcement_title'] ?? '');
        $data['agent_announcement'] = $this->stringValue($setting['announcement'] ?? '');
        $data['theme_config'] = $this->themeConfig($data['theme_config'] ?? [], $setting);
        $data['agent_context'] = $this->contextPayload($context);

        return $data;
    }

    private function contextPayload(array $context): array
    {
        $domainId = $context['agent_domain_id'] ?? null;

        return [
            'agent_user_id' => (int) ($context['agent_user_id'] ?? 0),
            'agent_domain_id' => $domainId === null || $domainId === '' ? null : (int) $domainId,
            'domain' => (string) ($context['domain'] ?? ''),
            'is_primary' => (bool) ($context['is_primary'] ?? false),
            'source' => (string) ($context['source'] ?? ''),
        ];
    }

    private function themeConfig(mixed $themeConfig, array $setting): array
    {
        $config = is_array($themeConfig) ? $themeConfig : [];
        $fieldMap = [
            'landing_theme' => 'landing_theme',
            'accent_color' => 'agent_accent_color',
            'support_name' => 'customer_service_name',
            'support_url' => 'customer_service_url',
            'customer_service_type' => 'customer_service_type',
            'customer_service_id' => 'customer_service_id',
        ];

        foreach ($fieldMap as $settingKey => $configKey) {
            $value = $this->stringValue($setting[$settingKey] ?? '');
            if ($value !== '') {
                $config[$configKey] = $value;
            }
        }

        return $config;
    }

    private function stringValue(mixed $value): string
    {
        return trim((string) $value);
    }
}
