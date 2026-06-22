<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;

class AgentSiteContextService
{
    private const STRING_KEYS = [
        'source',
        'domain',
        'site_name',
        'logo_url',
        'landing_theme',
        'accent_color',
        'support_name',
        'support_url',
        'customer_service_type',
        'customer_service_id',
        'announcement',
        'seo_title',
        'seo_description',
    ];

    public function __construct(
        private AgentCommerceContextResolver $contextResolver,
        private AgentSiteSettingService $settingService
    ) {}

    public function resolve(Request $request, ?User $user = null): ?array
    {
        $context = $this->contextResolver->resolveRequest($request, $user);
        if (!$context) {
            return null;
        }

        $setting = $this->settingService->resolve($context);
        if ($setting === []) {
            return null;
        }

        $payload = [
            'enabled' => (bool) ($setting['enabled'] ?? false),
            'agent_user_id' => $this->nullableInt($setting['agent_user_id'] ?? null),
            'agent_domain_id' => $this->nullableInt($setting['agent_domain_id'] ?? null),
            'source' => $context['source'] ?? '',
            'domain' => $context['domain'] ?? '',
            'site_name' => $setting['site_name'] ?? '',
            'logo_url' => $setting['logo_url'] ?? '',
            'landing_theme' => $setting['landing_theme'] ?? '',
            'accent_color' => $setting['accent_color'] ?? '',
            'support_name' => $setting['support_name'] ?? '',
            'support_url' => $setting['support_url'] ?? '',
            'customer_service_type' => $setting['customer_service_type'] ?? '',
            'customer_service_id' => $setting['customer_service_id'] ?? '',
            'announcement' => $setting['announcement'] ?? '',
            'seo_title' => $setting['seo_title'] ?? '',
            'seo_description' => $setting['seo_description'] ?? '',
            'created_at' => $this->nullableInt($setting['created_at'] ?? null),
            'updated_at' => $this->nullableInt($setting['updated_at'] ?? null),
        ];

        foreach (self::STRING_KEYS as $key) {
            $payload[$key] = trim((string) $payload[$key]);
        }

        return $payload;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        return (int) $value;
    }
}
