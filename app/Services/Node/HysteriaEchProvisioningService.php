<?php

declare(strict_types=1);

namespace App\Services\Node;

use App\Models\Server;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use App\Support\Hysteria2Ech;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class HysteriaEchProvisioningService
{
    // Managed fields are server-owned, never trusted from an admin form round trip.
    public static function normalize(array $settings, ?array $previous = null): array
    {
        if (!Hysteria2Ech::automatic($settings)) return $settings;
        if ($previous && Hysteria2Ech::automatic($previous)
            && data_get($previous, 'tls.server_name') === data_get($settings, 'tls.server_name')
            && preg_match('/^[a-f0-9]{32}$/D', (string) data_get($previous, 'ech.request_id'))) {
            $settings['ech'] = $previous['ech'];
        } else {
            $settings['ech'] = ['enabled' => true, 'mode' => 'automatic',
                'request_id' => bin2hex(random_bytes(16)), 'state' => 'pending'];
        }
        return $settings;
    }

    public static function applyReport(array $settings, array $report): array
    {
        $ech = $settings['ech'] ?? [];
        if (!Hysteria2Ech::automatic($settings)
            || !hash_equals((string) ($ech['request_id'] ?? ''), $report['request_id'])) {
            throw new InvalidArgumentException('ECH request is no longer active');
        }
        if (!Hysteria2Ech::validConfig($report['config'], (string) data_get($settings, 'tls.server_name'))
            || !self::validPath($report['key_file'])) {
            throw new InvalidArgumentException('Invalid public ECH report');
        }
        if (isset($ech['config']) && (!hash_equals($ech['config'], $report['config'])
            || !hash_equals($ech['key_file'], $report['key_file']))) {
            throw new InvalidArgumentException('ECH identity mismatch; existing key was preserved');
        }
        if ($report['phase'] === 'ready' && !isset($ech['config'])) {
            throw new InvalidArgumentException('ECH material must be prepared before activation');
        }
        $settings['ech'] = [...$ech, 'config' => $report['config'], 'key_file' => $report['key_file'],
            'state' => ($ech['state'] ?? '') === 'ready' ? 'ready' : $report['phase']];
        return $settings;
    }

    public function report(Server $authorizedNode, array $report): array
    {
        $changed = false;
        $result = DB::transaction(function () use ($authorizedNode, $report, &$changed): array {
            $node = Server::query()->lockForUpdate()->findOrFail($authorizedNode->id);
            if ($node->type !== 'hysteria' || !$node->enabled) {
                throw new InvalidArgumentException('ECH report requires an active HY2 node');
            }
            $settings = (array) $node->protocol_settings;
            $next = self::applyReport($settings, $report);
            $changed = $next !== $settings;
            if ($changed) $node->forceFill(['protocol_settings' => $next])->save();
            return ['request_id' => $report['request_id'], 'phase' => $next['ech']['state'],
                'config' => $next['ech']['config'], 'key_file' => $next['ech']['key_file']];
        });
        if ($changed) app(NodeRealtimePublisher::class)->invalidateConfigForServers(
            [(int) $authorizedNode->id], 'node.ech.provisioned');
        return $result;
    }

    public static function validPath(string $path): bool
    {
        return strlen($path) <= 4096 && str_starts_with($path, '/')
            && !preg_match('/[\x00-\x20\x7f]/', $path) && !in_array('..', explode('/', $path), true);
    }
}
