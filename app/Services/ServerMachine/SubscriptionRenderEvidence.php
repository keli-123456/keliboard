<?php

declare(strict_types=1);

namespace App\Services\ServerMachine;

use App\Jobs\RecordSubscriptionRenderJob;
use App\Models\User;
use App\Services\SubscriptionAiBehaviorService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Sampled server-side generation receipts, never proof of receipt, use or leakage. */
class SubscriptionRenderEvidence
{
    public const TABLE = 'v2_subscription_render_evidence';
    public const NODES = 64;
    public const PER_MINUTE = 30;
    public const RETENTION = 86400;

    public function collecting(User $user): bool
    {
        return config('app.key') && $user->id > 0 && !$user->is_admin && !$user->is_staff && !$user->banned
            && trim((string) $user->token) !== '' && app(MachineRiskSettings::class)->enabled()
            && Cache::remember('machine_risk:render_available', 30, fn () => Schema::hasTable(self::TABLE));
    }

    public function node(array $server): ?array
    {
        $id = filter_var($server['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $machine = filter_var($server['machine_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id || !$machine) return null;
        $values = array_intersect_key($server, array_flip(['type', 'runtime', 'host', 'port', 'ports', 'protocol_settings']));
        $canonical = function (mixed $value) use (&$canonical): mixed {
            if (!is_array($value)) return $value;
            if (!array_is_list($value)) ksort($value);
            return array_map($canonical, $value);
        };
        $encoded = json_encode($canonical($values), JSON_THROW_ON_ERROR, 32);
        if (strlen($encoded) > 65536) return null;
        return ['node_id' => $id, 'machine_id' => $machine,
            'version' => hash_hmac('sha256', $encoded, (string) config('app.key'))];
    }

    public function capture(User $user, array $snapshot, string $format): void
    {
        if (!$this->collecting($user) || !in_array($format, ['General', 'Clash', 'ClashMeta', 'KeliCore', 'Loon', 'QuantumultX',
            'Shadowrocket', 'Shadowsocks', 'SingBox', 'Stash', 'Surfboard', 'Surge'], true)) return;
        $now = time();
        $credential = app(SubscriptionAiBehaviorService::class)->credential($user);
        $identity = hash('sha256', $user->id . ':' . ($user->site_id ?? 0) . ':' . $credential);
        // No SQL writes or blocking locks on a subscription response. Gaps are explicit.
        $accountBudget = 'machine_risk:render_account:' . $identity . ':' . intdiv($now, 60);
        Cache::add($accountBudget, 0, 120);
        if (Cache::increment($accountBudget) > 2) return;
        $budget = 'machine_risk:render_budget:' . intdiv($now, 60);
        Cache::add($budget, 0, 120);
        if (Cache::increment($budget) > self::PER_MINUTE) return;
        $nodes = [];
        foreach (array_slice(array_values($snapshot['nodes'] ?? []), 0, self::NODES) as $node) {
            if (!is_array($node) || !is_int($node['node_id'] ?? null) || $node['node_id'] <= 0
                || !is_int($node['machine_id'] ?? null) || $node['machine_id'] <= 0
                || !is_string($node['version'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $node['version'])) return;
            $nodes[] = array_intersect_key($node, array_flip(['node_id', 'machine_id', 'version']));
        }
        if ($nodes === []) return;
        $receipt = ['receipt_key' => bin2hex(random_bytes(32)), 'user_id' => (int) $user->id,
            'site_id' => (int) ($user->site_id ?? 0), 'credential_hash' => $credential,
            'policy_revision' => app(MachineRiskSettings::class)->snapshot()['revision'],
            'generated_at' => $now, 'format' => $format, 'nodes' => $nodes,
            'capped' => (bool) ($snapshot['capped'] ?? false) || count($snapshot['nodes'] ?? []) > self::NODES];
        if (strlen(json_encode($receipt, JSON_THROW_ON_ERROR)) > 12288) return;
        RecordSubscriptionRenderJob::dispatch($receipt);
    }

    public function persist(array $receipt): void
    {
        $policy = app(MachineRiskSettings::class)->snapshot();
        if (!$policy['settings']['enabled'] || ($receipt['policy_revision'] ?? null) !== $policy['revision']
            || !Schema::hasTable(self::TABLE) || !is_int($receipt['generated_at'] ?? null)
            || $receipt['generated_at'] < time() - 120 || $receipt['generated_at'] > time() + 5) return;
        $user = User::query()->find($receipt['user_id']);
        if (!$user || $user->is_admin || $user->is_staff || $user->banned
            || (int) ($user->site_id ?? 0) !== $receipt['site_id']
            || !hash_equals(app(SubscriptionAiBehaviorService::class)->credential($user), $receipt['credential_hash'])) return;
        // Only the fixed, secret-free queue envelope can reach persistence.
        $nodes = [];
        foreach (array_slice((array) $receipt['nodes'], 0, self::NODES) as $node) {
            if (!is_array($node) || !is_int($node['node_id'] ?? null) || $node['node_id'] <= 0
                || !is_int($node['machine_id'] ?? null) || $node['machine_id'] <= 0
                || !is_string($node['version'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $node['version'])) return;
            $nodes[] = array_intersect_key($node, array_flip(['node_id', 'machine_id', 'version']));
        }
        if ($nodes === [] || !preg_match('/^[a-f0-9]{64}$/D', $receipt['receipt_key'] ?? '')) return;
        DB::table(self::TABLE)->insertOrIgnore(array_intersect_key($receipt, array_flip([
            'receipt_key', 'user_id', 'site_id', 'credential_hash', 'policy_revision', 'generated_at', 'format', 'capped',
        ])) + ['nodes' => json_encode($nodes, JSON_THROW_ON_ERROR), 'persisted_at' => time()]);
    }

    public function forMachine(int $machineId, int $at, string $episode): array
    {
        $result = ['source' => 'successful_server_side_generation', 'client_receipt_proven' => false,
            'configuration_fingerprint_kind' => 'node_export_input_not_runtime_ack',
            'lower_bounds_only' => true, 'window_seconds' => 1800, 'retention_seconds' => self::RETENTION,
            'global_budget_per_minute' => self::PER_MINUTE, 'rows_inspected' => 0, 'matched_receipts' => 0,
            'excluded_identity_rows' => 0, 'capped' => false, 'users' => []];
        if (!Schema::hasTable(self::TABLE)) return $result + ['state' => 'unavailable'];
        $policy = app(MachineRiskSettings::class)->snapshot();
        $rows = DB::table(self::TABLE)->where('policy_revision', $policy['revision'])
            ->whereBetween('generated_at', [$at - 1800, $at])->orderByDesc('generated_at')->orderByDesc('id')->limit(1001)->get();
        $result['capped'] = $rows->count() > 1000;
        $matched = [];
        foreach ($rows->take(1000) as $row) {
            $result['rows_inspected']++;
            $nodes = array_values(array_filter(json_decode($row->nodes, true) ?: [], fn ($n) => ($n['machine_id'] ?? null) === $machineId));
            if ($nodes !== []) $matched[] = [$row, $nodes];
        }
        $accounts = User::query()->whereIn('id', array_unique(array_map(fn ($v) => $v[0]->user_id, $matched)))
            ->get(['id', 'site_id', 'token', 'uuid', 'is_admin', 'is_staff'])->keyBy('id');
        $users = [];
        foreach ($matched as [$row, $nodes]) {
            $user = $accounts->get($row->user_id);
            if (!$user || $user->is_admin || $user->is_staff || (int) ($user->site_id ?? 0) !== (int) $row->site_id
                || !hash_equals(app(SubscriptionAiBehaviorService::class)->credential($user), $row->credential_hash)) {
                $result['excluded_identity_rows']++; continue;
            }
            $result['matched_receipts']++; $result['capped'] = $result['capped'] || (bool) $row->capped;
            $key = (int) $user->id;
            if (!isset($users[$key]) && count($users) >= 20) { $result['capped'] = true; continue; }
            $item = $users[$key] ?? ['ref' => substr(hash_hmac('sha256', 'render:' . $episode . ':' . $key, (string) config('app.key')), 0, 16),
                'user_id' => $key, 'site_id' => (int) $row->site_id, 'first_at' => (int) $row->generated_at,
                'last_at' => (int) $row->generated_at, 'receipts_lower_bound' => 0, 'versions' => []];
            $item['first_at'] = min($item['first_at'], (int) $row->generated_at);
            $item['receipts_lower_bound']++;
            foreach ($nodes as $node) {
                $versionKey = $node['node_id'] . ':' . $node['version'];
                if (count($item['versions']) >= 8 && !isset($item['versions'][$versionKey])) { $result['capped'] = true; continue; }
                $item['versions'][$versionKey] = ['node_id' => $node['node_id'], 'version' => substr($node['version'], 0, 12)];
            }
            $users[$key] = $item;
        }
        $result['users'] = array_values(array_map(function ($user) { $user['versions'] = array_values($user['versions']); return $user; }, $users));
        return $result + ['state' => 'sampled'];
    }

    public function prune(): void
    {
        if (!Schema::hasTable(self::TABLE)) return;
        $ids = DB::table(self::TABLE)->where('generated_at', '<', time() - self::RETENTION)->limit(1000)->pluck('id');
        DB::table(self::TABLE)->whereIn('id', $ids)->delete();
    }
}
