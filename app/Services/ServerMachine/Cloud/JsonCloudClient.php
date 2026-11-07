<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount;
use App\Services\ServerMachine\MachineEntryProbe;
use Illuminate\Support\Facades\Http;

abstract class JsonCloudClient implements CloudClient
{
    abstract protected function origin(): string;

    protected function request(MachineCloudAccount $account, string $method, string $path, array $data = []): array
    {
        try {
            $response = Http::withToken($account->credentials['api_token'])->acceptJson()->withoutRedirecting()
                ->connectTimeout(3)->timeout(5)->send($method, $this->origin() . $path,
                    $method === 'GET' ? ['query' => $data] : ['json' => $data ?: new \stdClass()]);
        } catch (\Throwable) { throw new CloudException($method === 'POST' ? 'outcome_uncertain' : 'provider_unavailable'); }
        if (in_array($response->status(), [401, 403], true)) throw new CloudException('provider_forbidden');
        if ($response->status() === 404) throw new CloudException('resource_missing');
        if ($response->status() === 429) throw new CloudException('provider_rate_limited');
        if ($response->serverError()) throw new CloudException($method === 'POST' ? 'outcome_uncertain' : 'provider_unavailable');
        if (!$response->successful()) throw new CloudException('provider_rejected');
        if ($response->status() === 204 && $method === 'POST') return [];
        if (strlen($response->body()) > 524288 || !is_array($json = $response->json())) throw new CloudException($method === 'POST' ? 'outcome_uncertain' : 'provider_invalid');
        return $json;
    }

    protected function timestamp(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d\d-\d\d[T ]\d\d:\d\d:\d\d(?:Z|\+00:00)?$/D', $value)) throw new CloudException('provider_invalid');
        try { $at = (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->getTimestamp(); }
        catch (\Throwable) { throw new CloudException('provider_invalid'); }
        if ($at > time() + 30) throw new CloudException('provider_invalid');
        return gmdate('Y-m-d\TH:i:s\Z', $at);
    }

    protected function ips(array $values): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($value) => MachineEntryProbe::publicIp($value), $values))));
    }

    protected function action(string $action): void
    {
        if (!in_array($action, ['boot', 'shutdown', 'reboot'], true)) throw new CloudException('invalid_action');
    }
}
