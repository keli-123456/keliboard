<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount;
use App\Models\MachineProvisionTask as Task;
use App\Services\ServerMachine\MachineEntryProbe;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// The caller owns scope/lease guards, durable delete intent and two separated absence readbacks.
class MachineCandidateCleanupClient
{
    public function __construct(private MachineProvisionProviders $providers) {}

    public static function lockKey(string $provider, string $externalId): string
    {
        return 'machine-cloud:identity:' . hash('sha256', $provider . ':' . strtolower($externalId));
    }

    public function inspect(Task $task): ?array
    {
        [$account, $client] = $this->context($task);
        try {
            $snapshot = $client->instance($account, $task->external_id);
        } catch (CloudRequestFailure $error) {
            // Only the exact resource GET's explicit 404 is absence, never an empty inventory.
            if (($error->decision['http_status'] ?? null) === 404
                && ($error->decision['kind'] ?? null) === 'resource_missing') return null;
            throw $error;
        }
        $this->receipt($task, $snapshot);
        return $snapshot;
    }

    public function delete(Task $task, ?callable $beforeDelete = null): void
    {
        [$account] = $this->context($task);
        // Account locks belong to the caller and must precede this account-independent identity lock.
        Cache::lock(self::lockKey($account->provider, $task->external_id), 120)
            ->block(2, fn () => $this->deleteLocked($task, $beforeDelete));
    }

    private function deleteLocked(Task $task, ?callable $beforeDelete): void
    {
        [$account, $client] = $this->context($task);
        $snapshot = $client->reconcile($account, $task->payload['request'], $task->dispatched_at);
        if ($snapshot === null) throw new CloudException('ownership_mismatch');
        $this->receipt($task, $snapshot);
        $this->receipt($task, $client->instance($account, $task->external_id));
        // Recheck the credential revision after remote reads; never switch accounts to clean up.
        [$account] = $this->context($task);
        $url = match ($account->provider) {
            'linode' => 'https://api.linode.com/v4/linode/instances/',
            'digitalocean' => 'https://api.digitalocean.com/v2/droplets/',
            'vultr' => 'https://api.vultr.com/v2/instances/',
            default => throw new CloudException('unsupported_provider'),
        } . $task->external_id;
        // Existing read transports require JSON bodies, so they cannot consume an empty DELETE 204.
        // Guard failures are local revocations, not ambiguous provider transport failures.
        if ($beforeDelete !== null) $beforeDelete();
        try {
            $response = Http::withToken($account->credentials['api_token'])->acceptJson()->withoutRedirecting()
                ->connectTimeout(3)->timeout(5)->send('DELETE', $url);
        } catch (\Throwable) {
            throw new CloudRequestFailure(app(CloudFailurePolicy::class)->transport($account->provider, 'DELETE'));
        }
        if (!$response->successful()) throw new CloudRequestFailure(app(CloudFailurePolicy::class)->response(
            $account->provider, 'DELETE', $response->status(),
            strlen($response->body()) <= 524288 ? $response->json() : null, $response->headers()));
        // Any 2xx is only a dispatch acknowledgement, not proof that the resource is gone.
    }

    private function context(Task $task): array
    {
        $payload = $task->payload ?? []; $plan = $payload['plan'] ?? null; $request = $payload['request'] ?? null;
        if (!is_array($plan) || !is_array($request) || !is_string($task->id) || !Str::isUuid($task->id)
            || !is_int($task->dispatched_at) || $task->dispatched_at <= 0 || $task->dispatched_at > time()
            || !is_string($task->external_id) || !is_string($task->provider_created_at)
            || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $task->provider_created_at))
            throw new CloudException('readback_identity_required');
        $this->addresses($task->public_ips);
        $account = MachineCloudAccount::query()->find($task->account_id);
        if (!$account || $account->revision !== $task->account_revision) throw new CloudException('account_changed');
        $client = $this->providers->client($account, $plan);
        if ($account->provider === 'vultr' ? !Str::isUuid($task->external_id)
            : !preg_match('/^[1-9][0-9]{0,15}$/D', $task->external_id)) throw new CloudException('invalid_resource');
        $identity = MachineProvisionProviders::requestIdentity($account->provider, $request);
        if (($plan['account_id'] ?? null) !== $task->account_id
            || $identity['label'] !== 'keli-' . str_replace('-', '', $task->id)
            || !is_string($task->marker) || !preg_match('/^keli-create-[a-f0-9]{24}$/D', $task->marker)
            || ($request['tags'] ?? null) !== [$task->marker]) throw new CloudException('ownership_mismatch');
        foreach (['region', 'size', 'image'] as $key)
            if ($identity[$key] !== ($plan[$key] ?? null)) throw new CloudException('ownership_mismatch');
        return [$account, $client];
    }

    private function receipt(Task $task, array $snapshot): void
    {
        if (($snapshot['external_id'] ?? null) !== $task->external_id
            || ($snapshot['provider_created_at'] ?? null) !== $task->provider_created_at
            || $this->addresses($snapshot['public_ips'] ?? null) !== $this->addresses($task->public_ips))
            throw new CloudException('ownership_mismatch');
        $identity = MachineProvisionProviders::requestIdentity($task->payload['plan']['provider'] ?? 'linode', $task->payload['request']);
        foreach (['label', 'region', 'size'] as $key)
            if (($snapshot[$key] ?? null) !== $identity[$key]) throw new CloudException('ownership_mismatch');
    }

    private function addresses(mixed $values): array
    {
        if (!is_array($values) || !array_is_list($values) || !$values) throw new CloudException('readback_identity_required');
        $ips = [];
        foreach ($values as $value) {
            $ip = MachineEntryProbe::publicIp($value);
            if ($ip === null || in_array($ip, $ips, true)) throw new CloudException('ownership_mismatch');
            $ips[] = $ip;
        }
        sort($ips, SORT_STRING);
        return $ips;
    }
}
