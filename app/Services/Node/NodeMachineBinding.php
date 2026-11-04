<?php

namespace App\Services\Node;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Support\Hysteria2Ech;
use App\Support\TrustTunnelCertificate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class NodeMachineBinding
{
    public static function available(): bool
    {
        return Schema::hasTable('v2_server_machine_binding');
    }

    public static function query(int $machineId): Builder
    {
        return Server::query()->where(function ($query) use ($machineId): void {
            $query->where('machine_id', $machineId);
            if (self::available()) $query->orWhereIn('id', DB::table('v2_server_machine_binding')->where('machine_id', $machineId)->select('server_id'));
        });
    }

    public static function machineIds(Server $node): array
    {
        $ids = [(int) $node->machine_id];
        if (self::available()) $ids = array_merge($ids, DB::table('v2_server_machine_binding')->where('server_id', $node->id)->pluck('machine_id')->all());
        return array_values(array_unique(array_filter(array_map('intval', $ids), fn ($id) => $id > 0)));
    }

    public static function replicaCounts(): array
    {
        if (!self::available()) return [];
        return DB::table('v2_server_machine_binding as b')->join('v2_server as s', 's.id', '=', 'b.server_id')
            ->where(fn ($query) => $query->whereNull('s.machine_id')->orWhereColumn('b.machine_id', '<>', 's.machine_id'))
            ->groupBy('b.machine_id')->selectRaw('b.machine_id, COUNT(*) as count')->pluck('count', 'b.machine_id')->all();
    }

    public static function assertCompatible(Server $node): void
    {
        $settings = (array) $node->protocol_settings;
        $localHysteriaCertificate = $node->type === 'hysteria'
            && in_array(data_get($settings, 'tls_settings.cert_mode'), ['self', 'dns', 'http'], true);
        if ($localHysteriaCertificate || Hysteria2Ech::automatic($settings) || TrustTunnelCertificate::automatic($node)) {
            throw ValidationException::withMessages(['machine_ids' => 'Automatic per-machine ECH or pinned certificates require separate nodes. Use compatible shared TLS/ECH material before attaching replicas.']);
        }
    }

    public static function revision(Server $node): string
    {
        $ids = self::machineIds($node); sort($ids);
        return hash('sha256', json_encode([(int) $node->id, (int) $node->machine_id, $ids, (string) $node->updated_at]));
    }

    public function replaceReplicas(int $nodeId, array $machineIds, ?string $expectedRevision = null): array
    {
        abort_unless(self::available(), 503, 'Instance binding migration is required.');
        return DB::transaction(function () use ($nodeId, $machineIds, $expectedRevision): array {
            $node = Server::query()->lockForUpdate()->findOrFail($nodeId);
            abort_if($expectedRevision !== null && !hash_equals(self::revision($node), $expectedRevision), 409, 'Instance bindings changed. Refresh before saving.');
            $ids = array_values(array_unique(array_filter(array_map('intval', $machineIds), fn ($id) => $id > 0 && $id !== (int) $node->machine_id)));
            if ($ids !== []) self::assertCompatible($node);
            if (ServerMachine::query()->whereIn('id', $ids)->where('is_active', true)->count() !== count($ids)) {
                throw ValidationException::withMessages(['machine_ids' => 'All replica machines must exist and be active.']);
            }
            $old = self::machineIds($node);
            DB::table('v2_server_machine_binding')->where('server_id', $nodeId)->whereNotIn('machine_id', $ids)->delete();
            foreach ($ids as $id) DB::table('v2_server_machine_binding')->insertOrIgnore(['server_id' => $nodeId, 'machine_id' => $id, 'created_at' => time()]);
            return array_values(array_unique(array_merge($old, $ids, [(int) $node->machine_id])));
        });
    }
}
