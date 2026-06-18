<?php

namespace App\Services;

use App\Models\AgentDomain;
use Illuminate\Http\Request;

class AgentDomainResolver
{
    public function resolveRequest(Request $request): ?array
    {
        return $this->resolveHost((string) $request->headers->get('host', ''));
    }

    public function resolveHost(string $host): ?array
    {
        $domain = $this->normalizeHost($host);
        if ($domain === '') {
            return null;
        }

        $row = AgentDomain::query()
            ->where('domain', $domain)
            ->where('status', AgentDomain::STATUS_ACTIVE)
            ->first();

        if (!$row) {
            return null;
        }

        return [
            'agent_user_id' => (int) $row->agent_user_id,
            'agent_domain_id' => (int) $row->id,
            'domain' => (string) $row->domain,
            'is_primary' => (bool) $row->is_primary,
        ];
    }

    public function normalizeHost(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return '';
        }

        if (str_contains($host, '://')) {
            $parsedHost = parse_url($host, PHP_URL_HOST);
            $host = is_string($parsedHost) ? $parsedHost : $host;
        }

        $host = preg_split('/[\/?#]/', $host, 2)[0] ?? '';
        $host = trim($host);
        if ($host === '') {
            return '';
        }

        if (str_starts_with($host, '[')) {
            $end = strpos($host, ']');
            $host = $end === false ? $host : substr($host, 1, $end - 1);
        } else {
            $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        }

        $host = strtolower(rtrim(trim($host), '.'));
        if ($host === '') {
            return '';
        }

        if (function_exists('idn_to_ascii')) {
            $variant = defined('INTL_IDNA_VARIANT_UTS46') ? INTL_IDNA_VARIANT_UTS46 : 1;
            $ascii = idn_to_ascii($host, 0, $variant);
            if (is_string($ascii) && $ascii !== '') {
                $host = strtolower($ascii);
            }
        }

        return $host;
    }
}
