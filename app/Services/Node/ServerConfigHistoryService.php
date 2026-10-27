<?php

declare(strict_types=1);

namespace App\Services\Node;

use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Models\ServerConfigRevision;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use App\Services\NodeRealtime\NodeRealtimeStatusService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class ServerConfigHistoryService
{
    // Sort order and runtime-generated certificate/ECH state are not restorable admin settings.
    private const FIELDS = ['type', 'runtime', 'code', 'spectific_key', 'name', 'host', 'port',
        'server_port', 'show', 'enabled', 'parent_id', 'machine_id', 'group_ids', 'route_ids',
        'tags', 'excludes', 'ips', 'rate', 'rate_time_enable', 'rate_time_ranges', 'protocol_settings'];
    private const PUBLIC_SETTINGS = ['version', 'network', 'congestion_control', 'hop_interval', 'tls',
        'tls.server_name', 'tls.allow_insecure', 'tls_settings.server_name', 'tls_settings.cert_mode',
        'tls_settings.provider', 'tls_settings.reject_unknown_sni', 'ech.enabled', 'ech.mode',
        'bandwidth.up', 'bandwidth.down', 'obfs.open', 'obfs.type', 'network_settings.masquerade.type'];

    public function available(): void
    {
        abort_unless(Schema::hasTable('v2_server_config_revision'), 503, '请先完成数据库更新，再使用配置历史和保存功能。');
    }

    public function snapshot(Server $server): array
    {
        $values = [];
        foreach (self::FIELDS as $key) {
            if (array_key_exists($key, $server->getAttributes())) $values[$key] = $server->getAttribute($key);
        }
        if (data_get($values, 'protocol_settings.ech.mode') === 'automatic') {
            $values['protocol_settings']['ech'] = ['enabled' => (bool) data_get($values, 'protocol_settings.ech.enabled'), 'mode' => 'automatic'];
        }
        foreach (['parent_id', 'machine_id', 'server_port'] as $key) {
            if (isset($values[$key])) $values[$key] = (int) $values[$key];
        }
        if (isset($values['rate'])) $values['rate'] = (float) $values['rate'];
        if (isset($values['port'])) $values['port'] = (string) $values['port'];
        return $this->canonical($values);
    }

    public function hash(array $snapshot): string
    {
        return hash_hmac('sha256', json_encode($this->canonical($snapshot), JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    private function canonical(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (!array_is_list($value)) ksort($value);
        return array_map(fn ($item) => $this->canonical($item), $value);
    }

    private function proposed(Server $server, array $payload): array
    {
        $copy = clone $server;
        $copy->fill(array_intersect_key($payload, array_flip(self::FIELDS)));
        return $this->snapshot($copy);
    }

    public function preview(Server $server, array $payload): array
    {
        $this->available();
        $before = $this->snapshot($server);
        $after = $this->proposed($server, $payload);
        $changes = $this->changes($before, $after);
        return ['server_id' => (int) $server->id, 'base_hash' => $this->hash($before),
            'draft_hash' => $this->hash($after), 'changes' => $changes, 'impact' => $this->impact($changes)];
    }

    public function persist(?int $id, array $payload, ?int $actorId, ?int $sourceId = null): Server
    {
        $this->available();
        $eventId = null;
        $groupChanged = false;
        $server = DB::transaction(function () use ($id, $payload, $actorId, $sourceId, &$eventId, &$groupChanged): Server {
            $server = $id ? Server::query()->lockForUpdate()->findOrFail($id) : new Server();
            $before = $id ? $this->snapshot($server) : [];
            if (isset($payload['expected_config_hash'])) {
                abort_unless(hash_equals($this->hash($before), $payload['expected_config_hash']), 409, '配置已被修改，请重新打开节点并查看差异。');
            }
            $after = $this->proposed($server, $payload);
            if (isset($payload['expected_draft_hash'])) {
                abort_unless(hash_equals($this->hash($after), $payload['expected_draft_hash']), 409, '待保存配置与预览不一致，请重新查看差异。');
            }
            $changes = $this->changes($before, $after);
            if ($id && $changes === []) return $server;
            if ($id && !ServerConfigRevision::where('server_id', $id)->exists()) {
                $this->record($server, $before, [], 'baseline', null, null, null);
            }
            $params = array_intersect_key($payload, array_flip(self::FIELDS));
            if (($params['type'] ?? $server->type) === 'hysteria' && isset($params['protocol_settings'])) {
                $params['protocol_settings'] = HysteriaEchProvisioningService::normalize(
                    $params['protocol_settings'], $id ? (array) $server->protocol_settings : null);
            }
            $server->fill($params)->save();
            $after = $this->snapshot($server->fresh());
            $eventId = (string) Str::uuid();
            $groupChanged = ($before['group_ids'] ?? []) !== ($after['group_ids'] ?? []);
            $this->record($server, $after, $changes, $sourceId ? 'restore' : 'save', $actorId, $eventId, $sourceId);
            return $server;
        });
        // A queued invalidation is not proof of delivery or application. Only matching receipts count.
        if ($eventId) {
            $notify = function () use ($server, $eventId, $groupChanged): void {
                try {
                    app(NodeRealtimePublisher::class)->invalidateConfigForServers([(int) $server->id], 'admin.server.saved', ['event_id' => $eventId]);
                    if ($groupChanged) app(NodeRealtimePublisher::class)->invalidateUsersForServers([(int) $server->id], 'admin.server.groups_saved');
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Saved node configuration notification unavailable',
                        ['server_id' => (int) $server->id, 'exception' => get_class($e)]);
                }
            };
            if (DB::transactionLevel() > 0) DB::afterCommit($notify);
            else $notify();
        }
        return $server;
    }

    private function record(Server $server, array $snapshot, array $changes, string $action, ?int $actorId, ?string $eventId, ?int $sourceId): void
    {
        ServerConfigRevision::create(['server_id' => $server->id, 'actor_id' => $actorId, 'action' => $action,
            'source_revision_id' => $sourceId, 'snapshot' => Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'config_hash' => $this->hash($snapshot), 'event_id' => $eventId,
            'changes' => $changes, 'impact' => $this->impact($changes)]);
    }

    public function history(Server $server, int $page): array
    {
        $this->available();
        $query = ServerConfigRevision::where('server_id', $server->id);
        $total = (clone $query)->count();
        $receipt = app(NodeRealtimeStatusService::class)->receiptsForServers([(int) $server->id])[$server->id]['config'] ?? null;
        $hash = $this->hash($this->snapshot($server));
        $items = $query->orderByDesc('id')->offset(($page - 1) * 15)->limit(15)->get([
            'id', 'actor_id', 'action', 'source_revision_id', 'config_hash', 'event_id', 'created_at', 'changes', 'impact',
        ])->map(function ($row) use ($hash, $receipt) {
            $status = 'unconfirmed';
            if ($row->action === 'baseline') $status = 'baseline';
            elseif ($row->event_id && $receipt && hash_equals($row->event_id, (string) ($receipt['event_id'] ?? ''))) {
                $status = in_array($receipt['status'], ['applied', 'received', 'queued', 'scheduled', 'restart_required', 'failed', 'error', 'ignored'], true)
                    ? $receipt['status'] : 'unconfirmed';
            }
            return ['id' => $row->id, 'actor_id' => $row->actor_id, 'action' => $row->action,
                'source_revision_id' => $row->source_revision_id, 'created_at' => $row->created_at->toIso8601String(),
                'changes' => $row->changes, 'impact' => $row->impact, 'current' => hash_equals($hash, $row->config_hash),
                'receipt_status' => $status, 'receipt_at' => $status !== 'unconfirmed' && $status !== 'baseline' ? $receipt['updated_at'] ?? null : null];
        })->all();
        return ['server_id' => (int) $server->id, 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => 15];
    }

    public function restorePayload(Server $server, int $revisionId): array
    {
        $this->available();
        $row = ServerConfigRevision::where('server_id', $server->id)->findOrFail($revisionId);
        try {
            $payload = json_decode(Crypt::decryptString($row->getRawOriginal('snapshot')), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(422, '历史快照无法解密，未修改当前配置。');
        }
        abort_unless(is_array($payload), 422, '历史快照格式不正确。');
        // Re-run today's complete form validation, including protocol compatibility checks.
        return ServerSave::validateSnapshot(array_intersect_key($payload, array_flip(self::FIELDS)));
    }

    public function changes(array $before, array $after): array
    {
        $rows = [];
        $walk = function (mixed $old, mixed $new, string $path) use (&$walk, &$rows): void {
            if ($old === $new) return;
            $public = !str_starts_with($path, 'protocol_settings')
                ? !in_array(explode('.', $path)[0], ['code', 'spectific_key', 'ips', 'excludes'], true)
                : in_array(substr($path, 18), self::PUBLIC_SETTINGS, true);
            if (is_array($old) || is_array($new)) {
                $canDescend = $path === 'protocol_settings' || in_array($path, ['protocol_settings.tls', 'protocol_settings.tls_settings',
                    'protocol_settings.ech', 'protocol_settings.bandwidth', 'protocol_settings.obfs', 'protocol_settings.network_settings',
                    'protocol_settings.network_settings.masquerade'], true);
                if ($canDescend) {
                    foreach (array_unique(array_merge(array_keys((array) $old), array_keys((array) $new))) as $key) {
                        $walk(is_array($old) ? $old[$key] ?? null : null, is_array($new) ? $new[$key] ?? null : null, "$path.$key");
                    }
                    return;
                }
            }
            $rows[] = ['field' => $path, 'before' => $public ? $old : ($old === null ? null : '[hidden]'),
                'after' => $public ? $new : ($new === null ? null : '[hidden]'), 'sensitive' => !$public];
        };
        foreach (array_unique(array_merge(array_keys($before), array_keys($after))) as $key) $walk($before[$key] ?? null, $after[$key] ?? null, $key);
        return $rows;
    }

    private function impact(array $changes): string
    {
        if (!$changes) return 'none';
        $fields = array_column($changes, 'field');
        foreach ($fields as $field) {
            if (in_array($field, ['type', 'runtime', 'server_port', 'parent_id', 'machine_id', 'enabled'], true)
                || preg_match('/^protocol_settings\.(ech|congestion_control|network|obfs|tls)/', $field)) return 'restart_possible';
        }
        return array_filter($fields, fn ($field) => str_starts_with($field, 'protocol_settings') || $field === 'route_ids')
            ? 'runtime_check' : 'panel_subscription';
    }
}
