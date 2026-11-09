<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount;

trait JsonProvisioning
{
    public function catalog(MachineCloudAccount $account): array
    {
        MachineProvisionProviders::assertProvider($account, static::PROVISION_PROVIDER);
        return parent::catalog($account);
    }
    public function instance(MachineCloudAccount $account, string $id): array
    {
        MachineProvisionProviders::assertProvider($account, static::PROVISION_PROVIDER);
        return parent::instance($account, $id);
    }

    public function preflight(MachineCloudAccount $account, array $plan): void
    {
        MachineProvisionProviders::assertProvider($account, static::PROVISION_PROVIDER);
        if (!MachineProvisionProviders::templateSupported($account->provider, $plan['region'], $plan['size'], $plan['image']))
            throw new CloudException('bootstrap_unsupported');
        $catalog = $this->catalog($account);
        $region = collect($catalog['regions'])->firstWhere('id', $plan['region']);
        $size = collect($catalog['sizes'])->firstWhere('id', $plan['size']);
        $image = collect($catalog['images'])->firstWhere('id', $plan['image']);
        if (!$region || !$size || !$image || !in_array($plan['region'], $size['regions'], true) || !in_array($plan['region'], $image['regions'], true)
            || $size['disk_gib'] < $image['min_disk_gib']) throw new CloudException('bootstrap_unsupported');
        $price = MachineProvisionProviders::cents($size['monthly_usd']);
        if ($price < 1 || $plan['monthly_limit_usd'] <= 0 || $price > (int) round($plan['monthly_limit_usd'] * 100)) throw new CloudException('budget_exceeded');
        if ($account->provider === 'digitalocean') {
            $body = $this->request($account, 'GET', '/account');
            if (($body['account']['status'] ?? null) !== 'active') throw new CloudException('provider_forbidden');
        } else {
            $available = $this->request($account, 'GET', '/regions/' . rawurlencode($plan['region']) . '/availability');
            if (!is_array($available['available_plans'] ?? null) || !in_array($plan['size'], $available['available_plans'], true))
                throw new CloudException('bootstrap_unsupported');
        }
    }

    public function create(MachineCloudAccount $account, array $request, int $since): array
    {
        MachineProvisionProviders::assertProvider($account, static::PROVISION_PROVIDER); $this->creationRequest($request);
        if (config('machine_provision.allow_live') !== true) throw new CloudException('provisioning_disabled');
        $data = $this->request($account, 'POST', $this->provisionPath(), $request);
        try {
            $raw = $data[$account->provider === 'digitalocean' ? 'droplet' : 'instance'] ?? null;
            if (!$this->creationMatches($raw, $request, $since)) throw new CloudException('identity_changed');
            return $this->provisionSnapshot($raw);
        } catch (\Throwable) {
            // A malformed 2xx response may already have created a paid resource.
            throw new CloudRequestFailure(app(CloudFailurePolicy::class)->response($account->provider, 'POST', 200));
        }
    }
    public function reconcile(MachineCloudAccount $account, array $request, int $since): ?array
    {
        MachineProvisionProviders::assertProvider($account, static::PROVISION_PROVIDER); $this->creationRequest($request, false);
        $rows = $this->provisionPages($account); $found = []; $seen = [];
        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            if ((!is_int($id) && !is_string($id)) || isset($seen[(string) $id])) throw new CloudException('reconcile_incomplete');
            $seen[(string) $id] = true;
            if ($this->creationMatches($row, $request, $since)) $found[(string) $id] = $this->provisionSnapshot($row);
        }
        if (count($found) > 1) throw new CloudException('duplicate_candidates');
        return $found ? array_values($found)[0] : null;
    }
    protected function provisionPath(): string { return static::PROVISION_PROVIDER === 'digitalocean' ? '/droplets' : '/instances'; }
    protected function provisionPages(MachineCloudAccount $account): array
    {
        MachineProvisionProviders::assertProvider($account, static::PROVISION_PROVIDER);
        try {
            return $this->catalogPages($account, $this->provisionPath(), $account->provider === 'digitalocean' ? 'droplets' : 'instances', microtime(true) + 20);
        } catch (CloudException $error) {
            if ($error->getMessage() === 'catalog_incomplete') throw new CloudException('reconcile_incomplete');
            throw $error;
        }
    }
    protected function provisionSnapshot(mixed $raw): array
    {
        $row = parent::normalize($raw);
        if (!is_array($raw['tags'] ?? null) || !array_is_list($raw['tags']) || count($raw['tags']) > 100) throw new CloudException('provider_invalid');
        foreach ($raw['tags'] as $tag) if (!is_string($tag) || strlen($tag) > 255) throw new CloudException('provider_invalid');
        $image = static::PROVISION_PROVIDER === 'digitalocean' ? ($raw['image']['slug'] ?? $raw['image']['id'] ?? null) : ($raw['os_id'] ?? null);
        if (is_int($image)) $image = (string) $image;
        if (!is_string($image) || $image === '' || strlen($image) > 100) throw new CloudException('provider_invalid');
        return $row + ['tags' => $raw['tags'], 'image' => $image, 'features' => $raw['features'] ?? null,
            'volume_ids' => $raw['volume_ids'] ?? null];
    }
    private function creationMatches(mixed $raw, array $request, int $since): bool
    {
        if (!is_array($raw) || !is_array($raw['tags'] ?? null)) throw new CloudException('provider_invalid');
        if (!in_array($request['tags'][0], $raw['tags'], true)) return false;
        $row = $this->provisionSnapshot($raw); $identity = MachineProvisionProviders::requestIdentity(static::PROVISION_PROVIDER, $request);
        foreach ($identity as $key => $value) if ($row[$key] !== $value) throw new CloudException('identity_changed');
        $at = strtotime($row['provider_created_at']);
        if ($at < $since - 30 || $at > $since + 1230) throw new CloudException('identity_changed');
        return true;
    }
    private function creationRequest(array $request, bool $checkTemplate = true): void
    {
        $provider = static::PROVISION_PROVIDER; $identity = MachineProvisionProviders::requestIdentity($provider, $request);
        if (!preg_match('/^keli-[a-f0-9]{32}$/D', $identity['label']) || !is_array($request['tags'] ?? null) || count($request['tags']) !== 1
            || !isset($request['tags'][0]) || !is_string($request['tags'][0]) || !preg_match('/^keli-create-[a-f0-9]{24}$/D', $request['tags'][0])) throw new CloudException('invalid_plan');
        if ($checkTemplate && !MachineProvisionProviders::templateSupported($provider, $identity['region'], $identity['size'], $identity['image']))
            throw new CloudException('bootstrap_unsupported');
        $expected = $provider === 'digitalocean' ? ['name', 'region', 'size', 'image', 'tags', 'backups', 'ipv6', 'monitoring', 'user_data']
            : ['label', 'hostname', 'region', 'plan', 'os_id', 'tags', 'backups', 'ddos_protection', 'activation_email', 'enable_ipv6', 'disable_public_ipv4', 'user_data'];
        if (array_diff(array_keys($request), $expected) || array_diff($expected, array_keys($request)) || !is_string($request['user_data'] ?? null)) throw new CloudException('invalid_plan');
        if ($provider === 'digitalocean') {
            if ($request['backups'] !== false || $request['ipv6'] !== true || $request['monitoring'] !== false
                || !str_starts_with($request['user_data'], '#cloud-config') || strlen(base64_encode($request['user_data'])) > 65535) throw new CloudException('invalid_plan');
        } elseif ($request['backups'] !== 'disabled' || $request['ddos_protection'] !== false || $request['activation_email'] !== false
            || $request['enable_ipv6'] !== true || $request['disable_public_ipv4'] !== false || $request['hostname'] !== $identity['label']
            || strlen($request['user_data']) > 65535 || !str_starts_with((string) base64_decode($request['user_data'], true), '#cloud-config')) throw new CloudException('invalid_plan');
    }
}
