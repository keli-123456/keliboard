<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount;

trait JsonFailoverCosts
{
    public function authority(MachineCloudAccount $account): bool
    {
        MachineProvisionProviders::assertProvider($account, static::PROVISION_PROVIDER);
        $body = $this->request($account, 'GET', '/account');
        if ($account->provider === 'digitalocean') {
            if (($body['account']['status'] ?? null) !== 'active') throw new CloudException('cost_scope_unknown');
            app(MachineCloudCredentialHealth::class)->assertAccountIdentity($account, $body);
        } elseif (!is_numeric($body['account']['balance'] ?? null) || !is_finite((float) $body['account']['balance'])) throw new CloudException('cost_scope_unknown');
        // Neither API read proves an unrestricted resource view. Missing resources stay unknown.
        return false;
    }
    public function completeInventory(MachineCloudAccount $account): array
    {
        $rows = [];
        foreach ($this->provisionPages($account) as $raw) {
            $row = $this->provisionSnapshot($raw);
            if (isset($rows[$row['external_id']])) throw new CloudException('cost_inventory_incomplete');
            $rows[$row['external_id']] = $row;
        }
        return $rows;
    }
    public function quote(MachineCloudAccount $account, array $row): int
    {
        MachineProvisionProviders::assertProvider($account, static::PROVISION_PROVIDER);
        $allowed = $account->provider === 'digitalocean' ? ['ipv6', 'private_networking', 'monitoring', 'droplet_agent'] : ['ipv6'];
        if (!is_array($row['features'] ?? null) || !array_is_list($row['features'])
            || count(array_filter($row['features'], fn ($feature) => !is_string($feature) || !in_array($feature, $allowed, true)))
            || count(array_filter($row['public_ips'], fn ($ip) => !str_contains($ip, ':'))) !== 1) throw new CloudException('cost_addons_unverified');
        if ($account->provider === 'digitalocean') {
            if (($row['volume_ids'] ?? null) !== []) throw new CloudException('cost_addons_unverified');
        } else {
            $blocks = $this->catalogPages($account, '/blocks', 'blocks', microtime(true) + 15);
            foreach ($blocks as $block) {
                if (!is_array($block) || !is_string($block['attached_to_instance'] ?? null)) throw new CloudException('cost_addons_unverified');
                if ($block['attached_to_instance'] === $row['external_id']) throw new CloudException('cost_addons_unverified');
            }
        }
        $catalog = $this->catalog($account); $size = collect($catalog['sizes'])->firstWhere('id', $row['size']);
        if (!$size || !in_array($row['region'], $size['regions'], true)) throw new CloudException('cost_price_unverified');
        return MachineProvisionProviders::cents($size['monthly_usd']);
    }
}
