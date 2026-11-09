<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount;
use Illuminate\Support\Facades\Http;

class LinodeProvisionClient extends LinodeClient implements ProvisionClient
{
    public function buildRequest(array $plan, string $id, string $marker, string $bootstrap): array
    {
        return ['label' => 'keli-' . str_replace('-', '', $id), 'region' => $plan['region'], 'type' => $plan['size'],
            'image' => $plan['image'], 'tags' => [$marker], 'booted' => true, 'backups_enabled' => false,
            'authorized_keys' => $plan['authorized_keys'], 'root_pass' => bin2hex(random_bytes(20)) . 'aA9!',
            'metadata' => ['user_data' => base64_encode($bootstrap)]];
    }
    public function preflight(MachineCloudAccount $account, array $plan): void
    {
        MachineProvisionProviders::assertProvider($account, 'linode');
        if (!in_array($plan['size'], config('machine_provision.supported_sizes', []), true)
            || !in_array($plan['image'], config('machine_provision.supported_images', []), true)) throw new CloudException('bootstrap_unsupported');
        $region = $this->request($account, 'GET', '/regions/' . rawurlencode($plan['region']));
        $image = $this->request($account, 'GET', '/images/' . rawurlencode($plan['image']));
        $type = $this->request($account, 'GET', '/linode/types/' . rawurlencode($plan['size']));
        $settings = $this->request($account, 'GET', '/account/settings');
        if (($region['id'] ?? null) !== $plan['region'] || ($region['status'] ?? null) !== 'ok' || !in_array('Metadata', $region['capabilities'] ?? [], true)
            || ($image['id'] ?? null) !== $plan['image'] || !in_array('cloud-init', $image['capabilities'] ?? [], true)
            || ($type['id'] ?? null) !== $plan['size'] || ($type['class'] ?? null) !== 'standard'
            || ($image['deprecated'] ?? true) !== false || ($image['status'] ?? null) !== 'available') throw new CloudException('bootstrap_unsupported');
        if (!array_key_exists('backups_enabled', $settings) || !is_bool($settings['backups_enabled'])) throw new CloudException('provider_invalid');
        if ($settings['backups_enabled']) throw new CloudException('account_backups_enabled');
        $price = $type['price']['monthly'] ?? null;
        if (!is_array($type['region_prices'] ?? null)) throw new CloudException('provider_invalid');
        $regional = array_filter($type['region_prices'], fn ($row) => is_array($row) && ($row['id'] ?? null) === $plan['region']);
        if (count($regional) > 1) throw new CloudException('provider_invalid');
        if ($regional) $price = array_values($regional)[0]['monthly'] ?? null;
        if (!is_numeric($price) || !is_finite((float) $price) || $price < 0 || $plan['monthly_limit_usd'] <= 0
            || (float) $price > $plan['monthly_limit_usd']) throw new CloudException('budget_exceeded');
    }

    public function create(MachineCloudAccount $account, array $request, int $since): array
    {
        MachineProvisionProviders::assertProvider($account, 'linode');
        if (config('machine_provision.allow_live') !== true) throw new CloudException('provisioning_disabled');
        try {
            $response = Http::withToken($account->credentials['api_token'])->acceptJson()->withoutRedirecting()
                ->connectTimeout(3)->timeout(15)->post('https://api.linode.com/v4/linode/instances', $request);
        } catch (\Throwable) { throw new CloudRequestFailure(app(CloudFailurePolicy::class)->transport('linode', 'POST')); }
        $failure = fn () => new CloudRequestFailure(app(CloudFailurePolicy::class)->response('linode', 'POST', $response->status(),
            strlen($response->body()) <= 524288 ? $response->json() : null, $response->headers()));
        if (!$response->successful() || strlen($response->body()) > 524288) throw $failure();
        try {
            $row = $response->json();
            if (!$this->matches($row, $request, $since)) throw new CloudException('identity_changed');
            return $this->normalize($row);
        } catch (\Throwable) { throw $failure(); }
    }

    public function reconcile(MachineCloudAccount $account, array $request, int $since): ?array
    {
        MachineProvisionProviders::assertProvider($account, 'linode');
        $matches = []; $complete = false;
        for ($page = 1; $page <= 3; $page++) {
            $data = $this->request($account, 'GET', '/linode/instances', ['page' => $page, 'page_size' => 100], ['label' => $request['label']]);
            if (!is_array($data['data'] ?? null) || !is_int($data['pages'] ?? null) || $data['pages'] < 0 || $data['pages'] > 3 || count($data['data']) > 100) throw new CloudException('reconcile_incomplete');
            foreach ($data['data'] as $row) {
                if ($this->matches($row, $request, $since)) $matches[(string) $row['id']] = $this->normalize($row);
            }
            if ($page >= $data['pages']) { $complete = true; break; }
        }
        if (!$complete) throw new CloudException('reconcile_incomplete');
        if (count($matches) > 1) throw new CloudException('duplicate_candidates');
        return $matches ? array_values($matches)[0] : null;
    }

    private function matches(mixed $row, array $request, int $since): bool
    {
        if (!is_array($row) || !is_array($row['tags'] ?? null) || !in_array($request['tags'][0], $row['tags'], true)) return false;
        $normalized = $this->normalize($row);
        $created = strtotime($normalized['provider_created_at']);
        if (($row['label'] ?? null) !== $request['label'] || ($row['region'] ?? null) !== $request['region']
            || ($row['type'] ?? null) !== $request['type'] || ($row['image'] ?? null) !== $request['image']
            || $created < $since - 30 || $created > $since + 1230) throw new CloudException('identity_changed');
        return true;
    }
}
