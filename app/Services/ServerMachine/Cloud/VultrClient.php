<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount;
use Illuminate\Support\Str;

class VultrClient extends JsonCloudClient
{
    use CloudCatalogReads;
    protected function origin(): string { return 'https://api.vultr.com/v2'; }
    private function id(string $id): void { if (!Str::isUuid($id)) throw new CloudException('invalid_resource'); }

    public function inventory(MachineCloudAccount $account, int $page): array
    {
        // Vultr uses opaque cursors. Bound traversal and never follow a returned URL.
        if ($page < 1 || $page > 10) throw new CloudException('pagination_limit');
        $cursor = null;
        for ($current = 1; $current <= $page; $current++) {
            $data = $this->request($account, 'GET', '/instances', ['per_page' => 100] + ($cursor ? ['cursor' => $cursor] : []));
            if (!is_array($data['instances'] ?? null) || count($data['instances']) > 100 || !is_int($data['meta']['total'] ?? null)
                || $data['meta']['total'] < 0) throw new CloudException('provider_invalid');
            if ($current < $page) {
                $next = $data['meta']['links']['next'] ?? null;
                if (!is_string($next) || !preg_match('~^[A-Za-z0-9+/_=-]{1,2048}$~D', $next) || $next === $cursor) throw new CloudException('pagination_changed');
                $cursor = $next;
            }
        }
        return ['resources' => array_map(fn ($row) => $this->normalize($row), $data['instances']), 'pages' => (int) ceil($data['meta']['total'] / 100)];
    }

    public function instance(MachineCloudAccount $account, string $id): array
    {
        $this->id($id); $row = $this->normalize($this->request($account, 'GET', '/instances/' . $id)['instance'] ?? null);
        if ($row['external_id'] !== $id) throw new CloudException('identity_changed');
        return $row;
    }

    public function power(MachineCloudAccount $account, string $id, string $action): void
    {
        $this->id($id); $this->action($action);
        $this->request($account, 'POST', '/instances/' . $id . '/' . ['boot' => 'start', 'shutdown' => 'halt', 'reboot' => 'reboot'][$action]);
    }

    public function safety(MachineCloudAccount $account, string $id, int $since): array
    {
        $snapshot = $this->instance($account, $id);
        if (!in_array($snapshot['status'], ['running', 'offline'], true)) throw new CloudException('provider_busy');
        // No event-history claim: automatic recovery remains unavailable for this provider.
        return [];
    }

    protected function normalize(mixed $row): array
    {
        if (!is_array($row)) throw new CloudException('provider_invalid');
        $id = (string) ($row['id'] ?? ''); $this->id($id);
        $status = match (true) {
            ($row['status'] ?? '') !== 'active' => 'unknown',
            ($row['server_status'] ?? '') === 'locked' && ($row['power_status'] ?? '') === 'stopped' => 'shutting_down',
            ($row['server_status'] ?? '') === 'locked' => 'busy',
            ($row['power_status'] ?? '') === 'stopped' && ($row['server_status'] ?? '') === 'ok' => 'offline',
            ($row['power_status'] ?? '') === 'running' && ($row['server_status'] ?? '') === 'ok' => 'running',
            default => 'unknown',
        };
        return ['external_id' => $id, 'label' => mb_substr((string) ($row['label'] ?? $id), 0, 100),
            'region' => mb_substr((string) ($row['region'] ?? ''), 0, 60), 'size' => mb_substr((string) ($row['plan'] ?? ''), 0, 60),
            'provider_created_at' => $this->timestamp($row['date_created'] ?? null), 'status' => $status,
            'public_ips' => $this->ips([$row['main_ip'] ?? null, $row['v6_main_ip'] ?? null]), 'observed_at' => time()];
    }
}
