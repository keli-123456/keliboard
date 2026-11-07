<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount;
use App\Services\ServerMachine\MachineEntryProbe;
use Illuminate\Support\Facades\Http;

class LinodeClient implements CloudClient
{
    public function inventory(MachineCloudAccount $account, int $page): array
    {
        $data = $this->request($account, 'GET', '/linode/instances', ['page' => $page, 'page_size' => 100]);
        if (!is_array($data['data'] ?? null) || !is_int($data['pages'] ?? null) || $data['pages'] < 0 || count($data['data']) > 100) throw new CloudException('provider_invalid');
        return ['resources' => array_map(fn ($row) => $this->normalize($row), $data['data']), 'pages' => $data['pages']];
    }

    public function instance(MachineCloudAccount $account, string $id): array
    {
        $this->validateId($id);
        $result = $this->normalize($this->request($account, 'GET', '/linode/instances/' . $id));
        if ($result['external_id'] !== $id) throw new CloudException('identity_changed');
        return $result;
    }

    public function power(MachineCloudAccount $account, string $id, string $action): void
    {
        $this->validateId($id);
        if (!in_array($action, ['boot', 'shutdown', 'reboot'], true)) throw new CloudException('invalid_action');
        // No retry: a transport failure after POST is an ambiguous outcome.
        $this->request($account, 'POST', '/linode/instances/' . $id . '/' . $action);
    }

    public function safety(MachineCloudAccount $account, string $id, int $since): array
    {
        $this->validateId($id);
        $maintenance = $this->pages($account, '/account/maintenance');
        foreach ($maintenance as $row) {
            if (!isset($row['entity']['id'], $row['entity']['type'], $row['status'])) throw new CloudException('safety_unknown');
            if ((string) $row['entity']['id'] === $id && $row['entity']['type'] === 'linode'
                && !in_array($row['status'], ['completed', 'canceled'], true)) throw new CloudException('maintenance');
        }
        // Include old unfinished events; a recent-only query can miss long migrations.
        $rows = $this->pages($account, '/account/events', ['entity.id' => (int) $id, 'entity.type' => 'linode', '+order_by' => 'created', '+order' => 'desc']);
        $events = [];
        foreach ($rows as $row) {
            if ((string) ($row['entity']['id'] ?? '') !== $id || ($row['entity']['type'] ?? '') !== 'linode') continue;
            if (!is_numeric($row['id'] ?? null) || !is_string($row['action'] ?? null) || !is_string($row['status'] ?? null)) throw new CloudException('safety_unknown');
            if (!in_array($row['status'], ['scheduled', 'started', 'in_progress', 'completed', 'finished', 'failed', 'notification', 'canceled'], true)) throw new CloudException('safety_unknown');
            $created = $this->timestamp($row['created'] ?? null);
            if ($created < $since && !in_array($row['status'], ['scheduled', 'started', 'in_progress'], true)) continue;
            $events[] = ['id' => (int) $row['id'], 'action' => $row['action'], 'status' => $row['status'], 'at' => $created];
        }
        return $events;
    }

    private function pages(MachineCloudAccount $account, string $path, array $filter = []): array
    {
        $result = [];
        for ($page = 1; $page <= 3; $page++) {
            $json = $this->request($account, 'GET', $path, ['page' => $page, 'page_size' => 100], $filter);
            if (!is_array($json['data'] ?? null) || !is_int($json['pages'] ?? null) || $json['pages'] > 3 || $json['pages'] < 0 || count($json['data']) > 100) throw new CloudException('safety_unknown');
            $result = [...$result, ...$json['data']];
            if ($page >= $json['pages']) return $result;
        }
        throw new CloudException('safety_unknown');
    }

    protected function request(MachineCloudAccount $account, string $method, string $path, array $query = [], array $filter = []): array
    {
        try {
            $client = Http::withToken($account->credentials['api_token'])->acceptJson()->connectTimeout(3)->timeout(5)->withoutRedirecting();
            if ($filter) $client = $client->withHeaders(['X-Filter' => json_encode($filter, JSON_THROW_ON_ERROR)]);
            $response = $client->send($method, 'https://api.linode.com/v4' . $path, $method === 'GET' ? ['query' => $query] : ['json' => new \stdClass()]);
        } catch (\Throwable) { throw new CloudException($method === 'POST' ? 'outcome_uncertain' : 'provider_unavailable'); }
        if (in_array($response->status(), [401, 403], true)) throw new CloudException('provider_forbidden');
        if ($response->status() === 404) throw new CloudException('resource_missing');
        if ($response->status() === 429) throw new CloudException('provider_rate_limited');
        if ($response->serverError() && $method === 'POST') throw new CloudException('outcome_uncertain');
        if ($response->serverError()) throw new CloudException('provider_unavailable');
        if (!$response->successful() || strlen($response->body()) > 524288) throw new CloudException('provider_rejected');
        $data = $response->json();
        if (!is_array($data)) throw new CloudException($method === 'POST' ? 'outcome_uncertain' : 'provider_invalid');
        return $data;
    }

    protected function normalize(mixed $row): array
    {
        if (!is_array($row)) throw new CloudException('provider_invalid');
        $id = (string) ($row['id'] ?? ''); $this->validateId($id);
        $status = (string) ($row['status'] ?? '');
        if (!preg_match('/^[a-z_]{1,40}$/D', $status)) throw new CloudException('provider_invalid');
        $ips = [];
        foreach ([...(array) ($row['ipv4'] ?? []), explode('/', (string) ($row['ipv6'] ?? ''))[0]] as $value) {
            if ($ip = MachineEntryProbe::publicIp($value)) $ips[] = $ip;
        }
        return ['external_id' => $id, 'label' => mb_substr((string) ($row['label'] ?? $id), 0, 100),
            'region' => mb_substr((string) ($row['region'] ?? ''), 0, 60), 'size' => mb_substr((string) ($row['type'] ?? ''), 0, 60),
            'provider_created_at' => gmdate('Y-m-d\TH:i:s\Z', $this->timestamp($row['created'] ?? null)),
            'status' => $status, 'public_ips' => array_values(array_unique($ips)), 'observed_at' => time()];
    }

    private function validateId(string $id): void
    {
        if (!preg_match('/^[1-9][0-9]{0,15}$/D', $id)) throw new CloudException('invalid_resource');
    }
    private function timestamp(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:Z)?$/D', $value)) throw new CloudException('provider_invalid');
        try { $at = (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp(); }
        catch (\Throwable) { throw new CloudException('provider_invalid'); }
        if ($at > time() + 30) throw new CloudException('provider_invalid');
        return $at;
    }
}
