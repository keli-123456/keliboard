<?php
namespace App\Services\ServerMachine\Cloud;

class DigitalOceanProvisionClient extends DigitalOceanClient implements ProvisionClient
{
    use JsonProvisioning;
    protected const PROVISION_PROVIDER = 'digitalocean';

    public function buildRequest(array $plan, string $id, string $marker, string $bootstrap): array
    {
        return ['name' => 'keli-' . str_replace('-', '', $id), 'region' => $plan['region'], 'size' => $plan['size'],
            'image' => $plan['image'], 'tags' => [$marker], 'backups' => false, 'ipv6' => true, 'monitoring' => false,
            'user_data' => MachineProvisionProviders::cloudInit($plan, $bootstrap)];
    }
}
