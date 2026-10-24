<?php

declare(strict_types=1);

namespace Plugin\SubscriptionControl\Services;

use App\Models\Plugin;
use Illuminate\Support\Facades\DB;

final class SubscriptionSourceIpBlockService
{
    public function list(): array
    {
        $config = $this->config();
        $entries = $this->parseEntries((string) ($config['source_ip_deny_cidrs'] ?? ''));
        $events = $this->eventsByIp($entries);

        return [
            'items' => array_map(function (string $entry) use ($events): array {
                $matched = $events[$entry] ?? [];
                $last = $matched[0] ?? null;
                $first = $matched ? $matched[count($matched) - 1] : null;

                return [
                    'entry' => $entry,
                    'source_type' => $matched ? 'ua_blacklist' : 'manual',
                    'event_count' => count($matched),
                    'first_seen_at' => $first['created_at'] ?? null,
                    'last_seen_at' => $last['created_at'] ?? null,
                    'last_email' => $this->firstNonEmpty($matched, 'email'),
                    'last_user_agent' => $this->firstNonEmpty($matched, 'user_agent'),
                    'client_ip_source' => $this->firstNonEmpty($matched, 'client_ip_source'),
                    'proxy_ip' => $this->firstNonEmpty($matched, 'proxy_ip'),
                    'ip_type' => $this->firstNonEmpty($matched, 'ip_type'),
                    'ip_asn' => $this->firstNonEmpty($matched, 'ip_asn'),
                    'ip_org' => $this->firstNonEmpty($matched, 'ip_org'),
                    'region' => $this->firstNonEmpty($matched, 'region'),
                    'node_synced' => false,
                ];
            }, $entries),
            'total' => count($entries),
        ];
    }

    public function unblock(string $entry): array
    {
        $entry = trim($entry);
        if ($entry === '') {
            return ['removed' => false, 'entry' => $entry, 'remaining' => 0];
        }

        return DB::transaction(function () use ($entry): array {
            $plugin = $this->plugin(true);
            if (!$plugin) {
                return ['removed' => false, 'entry' => $entry, 'remaining' => 0];
            }

            $config = $this->decodeConfig($plugin->config);
            $entries = $this->parseEntries((string) ($config['source_ip_deny_cidrs'] ?? ''));
            $next = array_values(array_filter(
                $entries,
                static fn(string $value): bool => strcasecmp($value, $entry) !== 0
            ));
            $removed = count($next) !== count($entries);

            if ($removed) {
                $config['source_ip_deny_cidrs'] = implode("\n", $next);
                $config['enable_source_ip_denylist'] = true;
                $plugin->config = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $plugin->save();
            }

            return [
                'removed' => $removed,
                'entry' => $entry,
                'remaining' => count($next),
            ];
        });
    }

    private function config(): array
    {
        $plugin = $this->plugin(false);
        return $plugin ? $this->decodeConfig($plugin->config) : [];
    }

    private function plugin(bool $lock): ?Plugin
    {
        $query = Plugin::query()->where('code', 'subscription_control');
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function decodeConfig(mixed $config): array
    {
        if (is_array($config)) {
            return $config;
        }
        if (!is_string($config) || trim($config) === '') {
            return [];
        }

        $decoded = json_decode($config, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function parseEntries(string $text): array
    {
        $entries = preg_split('/[\r\n,]+/', $text) ?: [];
        $entries = array_map(static fn(string $entry): string => trim($entry), $entries);
        $entries = array_values(array_filter($entries, static fn(string $entry): bool => $entry !== ''));

        return array_values(array_unique($entries));
    }

    private function firstNonEmpty(array $rows, string $key): mixed
    {
        foreach ($rows as $row) {
            $value = $row[$key] ?? null;
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function eventsByIp(array $entries): array
    {
        if (!$entries) {
            return [];
        }

        $rows = DB::table('v2_subscription_control_event')
            ->where('code', 'ua_blacklist')
            ->whereIn('client_ip', $entries)
            ->orderByDesc('created_at')
            ->limit(1000)
            ->get()
            ->map(static fn(object $row): array => (array) $row)
            ->all();

        $grouped = [];
        foreach ($rows as $row) {
            $ip = (string) ($row['client_ip'] ?? '');
            if ($ip === '') {
                continue;
            }
            $grouped[$ip][] = $row;
        }

        return $grouped;
    }
}
