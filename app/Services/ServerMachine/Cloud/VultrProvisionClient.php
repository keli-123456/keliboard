<?php
namespace App\Services\ServerMachine\Cloud;

class VultrProvisionClient extends VultrClient implements ProvisionClient
{
    use JsonProvisioning;
    protected const PROVISION_PROVIDER = 'vultr';

    public function buildRequest(array $plan, string $id, string $marker, string $bootstrap): array
    {
        if (!preg_match('/^[1-9][0-9]{0,8}$/D', $plan['image'])) throw new CloudException('invalid_plan');
        $label = 'keli-' . str_replace('-', '', $id);
        return ['label' => $label, 'hostname' => $label, 'region' => $plan['region'], 'plan' => $plan['size'], 'os_id' => (int) $plan['image'],
            'tags' => [$marker], 'backups' => 'disabled', 'ddos_protection' => false, 'activation_email' => false,
            'enable_ipv6' => true, 'disable_public_ipv4' => false, 'user_data' => base64_encode(MachineProvisionProviders::cloudInit($plan, $bootstrap))];
    }
}
