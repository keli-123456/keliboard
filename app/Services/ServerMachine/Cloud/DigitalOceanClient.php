<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount;

class DigitalOceanClient extends JsonCloudClient
{
    use CloudCatalogReads;
    protected function origin(): string { return 'https://api.digitalocean.com/v2'; }
    private function id(string $id): void { if (!preg_match('/^[1-9][0-9]{0,15}$/D', $id)) throw new CloudException('invalid_resource'); }

    public function inventory(MachineCloudAccount $account, int $page): array
    {
        $data = $this->request($account, 'GET', '/droplets', ['page' => $page, 'per_page' => 100]);
        if (!is_array($data['droplets'] ?? null) || count($data['droplets']) > 100 || !is_int($data['meta']['total'] ?? null)
            || $data['meta']['total'] < 0) throw new CloudException('provider_invalid');
        return ['resources' => array_map(fn ($row) => $this->normalize($row), $data['droplets']), 'pages' => (int) ceil($data['meta']['total'] / 100)];
    }

    public function instance(MachineCloudAccount $account, string $id): array
    {
        $this->id($id); $row = $this->normalize($this->request($account, 'GET', '/droplets/' . $id)['droplet'] ?? null);
        if ($row['external_id'] !== $id) throw new CloudException('identity_changed');
        return $row;
    }

    public function power(MachineCloudAccount $account, string $id, string $action): void
    {
        $this->id($id); $this->action($action);
        $type = ['boot' => 'power_on', 'shutdown' => 'shutdown', 'reboot' => 'reboot'][$action];
        $data = $this->request($account, 'POST', '/droplets/' . $id . '/actions', ['type' => $type]);
        $row = $data['action'] ?? null;
        if (!is_array($row) || (string) ($row['resource_id'] ?? '') !== $id || ($row['resource_type'] ?? '') !== 'droplet'
            || ($row['type'] ?? '') !== $type || !is_int($row['id'] ?? null) || !in_array($row['status'] ?? null, ['in-progress', 'completed'], true)) throw new CloudException('outcome_uncertain');
    }

    public function safety(MachineCloudAccount $account, string $id, int $since): array
    {
        $this->id($id); $events = [];
        for ($page = 1; $page <= 3; $page++) {
            $data = $this->request($account, 'GET', '/droplets/' . $id . '/actions', ['page' => $page, 'per_page' => 100]);
            if (!is_array($data['actions'] ?? null) || count($data['actions']) > 100 || !is_int($data['meta']['total'] ?? null)
                || $data['meta']['total'] < 0 || $data['meta']['total'] > 300) throw new CloudException('safety_unknown');
            foreach ($data['actions'] as $row) {
                if (!is_array($row) || (string) ($row['resource_id'] ?? '') !== $id || ($row['resource_type'] ?? '') !== 'droplet'
                    || !is_int($row['id'] ?? null) || !is_string($row['type'] ?? null)
                    || !in_array($row['status'] ?? null, ['in-progress', 'completed', 'errored'], true)) throw new CloudException('safety_unknown');
                $at = strtotime($this->timestamp($row['started_at'] ?? null));
                if ($at < $since && $row['status'] !== 'in-progress') continue;
                $events[] = ['id' => $row['id'], 'action' => $row['type'], 'status' => ['in-progress' => 'in_progress', 'completed' => 'completed', 'errored' => 'failed'][$row['status']], 'at' => $at];
            }
            if ($page * 100 >= $data['meta']['total']) return $events;
        }
        throw new CloudException('safety_unknown');
    }

    private function normalize(mixed $row): array
    {
        if (!is_array($row)) throw new CloudException('provider_invalid');
        $id = (string) ($row['id'] ?? ''); $this->id($id);
        if (!in_array($row['status'] ?? '', ['new', 'active', 'off', 'archive'], true) || !is_bool($row['locked'] ?? null)) throw new CloudException('provider_invalid');
        $ips = [];
        foreach (['v4', 'v6'] as $family) foreach ($row['networks'][$family] ?? [] as $ip) if (($ip['type'] ?? null) === 'public') $ips[] = $ip['ip_address'] ?? null;
        return ['external_id' => $id, 'label' => mb_substr((string) ($row['name'] ?? $id), 0, 100),
            'region' => mb_substr((string) ($row['region']['slug'] ?? ''), 0, 60), 'size' => mb_substr((string) ($row['size_slug'] ?? ''), 0, 60),
            'provider_created_at' => $this->timestamp($row['created_at'] ?? null), 'public_ips' => $this->ips($ips), 'observed_at' => time(),
            'status' => $row['locked'] ? 'busy' : match ($row['status']) { 'active' => 'running', 'off' => 'offline', 'new' => 'booting', default => 'unknown' }];
    }
}
