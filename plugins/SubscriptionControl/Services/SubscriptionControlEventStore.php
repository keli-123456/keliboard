<?php

declare(strict_types=1);

namespace Plugin\SubscriptionControl\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

final class SubscriptionControlEventStore
{
    private const TABLE = 'v2_subscription_control_event';
    private const JSON_FIELDS = ['ua_categories', 'regions', 'online_regions', 'signals', 'ip_risk_tags'];
    private const BOOL_FIELDS = ['trusted_proxy', 'active_plan_user', 'cooldown_hit', 'email_sent', 'telegram_sent'];
    private const INT_FIELDS = [
        'user_id',
        'ip_asn',
        'online_ip_count',
        'source_user_count',
        'source_user_threshold',
        'ip_count',
        'risk_score',
        'score_threshold',
        'hit_count',
        'used_traffic',
        'transfer_enable',
        'threshold',
        'created_at',
        'updated_at',
    ];

    public function append(array $event, int $retentionDays = 3): void
    {
        if (!$this->available()) {
            return;
        }

        try {
            $now = time();
            $createdAt = $this->intOrNull($event['created_at'] ?? null) ?? $now;
            DB::table(self::TABLE)->insert($this->normalizeForInsert($event, $createdAt, $now));
            $this->prune($retentionDays);
        } catch (\Throwable $e) {
            Log::warning('[SubscriptionControl] 风控事件落库失败', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function recent(int $limit = 50, string $email = '', int $retentionDays = 3): array
    {
        if (!$this->available()) {
            return [];
        }

        try {
            $days = max(1, $retentionDays);
            $query = DB::table(self::TABLE)
                ->where('created_at', '>=', time() - ($days * 86400))
                ->orderByDesc('created_at')
                ->orderByDesc('id');
            $email = trim($email);
            if ($email !== '') {
                $query->where('email', 'like', '%' . $email . '%');
            }

            return $query
                ->limit(max(1, min(100, $limit)))
                ->get()
                ->map(fn($row): array => $this->rowToEvent((array) $row))
                ->all();
        } catch (\Throwable $e) {
            Log::warning('[SubscriptionControl] 风控事件读取失败', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    public function prune(int $retentionDays = 3): void
    {
        if (!$this->available()) {
            return;
        }

        $days = max(1, $retentionDays);
        DB::table(self::TABLE)->where('created_at', '<', time() - ($days * 86400))->delete();
    }

    public function available(): bool
    {
        try {
            return Schema::hasTable(self::TABLE);
        } catch (\Throwable) {
            return false;
        }
    }

    private function normalizeForInsert(array $event, int $createdAt, int $now): array
    {
        return [
            'event_id' => $this->stringOrNull($event['id'] ?? null) ?? uniqid('sc_', true),
            'user_id' => $this->intOrNull($event['user_id'] ?? null),
            'email' => $this->stringOrNull($event['email'] ?? null),
            'code' => $this->stringOrNull($event['code'] ?? null) ?? 'subscription_risk',
            'reason' => $this->stringOrNull($event['reason'] ?? null) ?? '订阅访问行为异常',
            'action' => $this->stringOrNull($event['action'] ?? null) ?? 'block',
            'client_ip' => $this->stringOrNull($event['client_ip'] ?? null),
            'proxy_ip' => $this->stringOrNull($event['proxy_ip'] ?? null),
            'client_ip_source' => $this->stringOrNull($event['client_ip_source'] ?? null),
            'trusted_proxy' => $this->boolOrNull($event['trusted_proxy'] ?? null),
            'cf_ray' => $this->stringOrNull($event['cf_ray'] ?? null),
            'ip_asn' => $this->intOrNull($event['ip_asn'] ?? null),
            'ip_prefix' => $this->stringOrNull($event['ip_prefix'] ?? null),
            'ip_country' => $this->stringOrNull($event['ip_country'] ?? null),
            'ip_registry' => $this->stringOrNull($event['ip_registry'] ?? null),
            'ip_org' => $this->stringOrNull($event['ip_org'] ?? null),
            'ip_type' => $this->stringOrNull($event['ip_type'] ?? null),
            'ip_risk_tags' => $this->jsonOrNull($event['ip_risk_tags'] ?? null),
            'user_agent' => $this->stringOrNull($event['user_agent'] ?? null),
            'ua_category' => $this->stringOrNull($event['ua_category'] ?? null),
            'ua_categories' => $this->jsonOrNull($event['ua_categories'] ?? null),
            'region' => $this->stringOrNull($event['region'] ?? null),
            'regions' => $this->jsonOrNull($event['regions'] ?? null),
            'online_regions' => $this->jsonOrNull($event['online_regions'] ?? null),
            'online_ip_count' => $this->intOrNull($event['online_ip_count'] ?? null),
            'source_user_count' => $this->intOrNull($event['source_user_count'] ?? null),
            'source_user_threshold' => $this->intOrNull($event['source_user_threshold'] ?? null),
            'ip_count' => $this->intOrNull($event['ip_count'] ?? null),
            'risk_score' => $this->intOrNull($event['risk_score'] ?? null),
            'score_threshold' => $this->intOrNull($event['score_threshold'] ?? null),
            'hit_count' => $this->intOrNull($event['hit_count'] ?? null),
            'signals' => $this->jsonOrNull($event['signals'] ?? null),
            'active_plan_user' => $this->boolOrNull($event['active_plan_user'] ?? null),
            'used_traffic' => $this->intOrNull($event['used_traffic'] ?? null),
            'transfer_enable' => $this->intOrNull($event['transfer_enable'] ?? null),
            'threshold' => $this->intOrNull($event['threshold'] ?? null),
            'cooldown_hit' => (bool) ($event['cooldown_hit'] ?? false),
            'email_sent' => (bool) ($event['email_sent'] ?? false),
            'telegram_sent' => (bool) ($event['telegram_sent'] ?? false),
            'created_at' => $createdAt,
            'updated_at' => $this->intOrNull($event['updated_at'] ?? null) ?? $now,
        ];
    }

    private function rowToEvent(array $row): array
    {
        $event = $row;
        $event['id'] = (string) ($row['event_id'] ?? $row['id'] ?? '');
        unset($event['event_id']);

        foreach (self::JSON_FIELDS as $field) {
            $event[$field] = $this->decodeJsonField($row[$field] ?? null);
        }

        foreach (self::BOOL_FIELDS as $field) {
            $event[$field] = isset($row[$field]) ? (bool) $row[$field] : null;
        }

        foreach (self::INT_FIELDS as $field) {
            if (array_key_exists($field, $event) && $event[$field] !== null) {
                $event[$field] = (int) $event[$field];
            }
        }

        return $event;
    }

    private function decodeJsonField(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function jsonOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return json_encode(is_array($value) ? array_values($value) : [$value], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function stringOrNull(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));
        return $text === '' ? null : $text;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function boolOrNull(mixed $value): ?bool
    {
        return $value === null ? null : (bool) $value;
    }
}
