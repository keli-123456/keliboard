<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount;
use Symfony\Component\Yaml\Yaml;

class MachineProvisionProviders
{
    public const SUPPORTED = ['linode', 'digitalocean', 'vultr'];

    public function client(MachineCloudAccount $account, ?array $plan = null): ProvisionClient
    {
        if ($plan !== null && ($plan['provider'] ?? 'linode') !== $account->provider) throw new CloudException('provider_mismatch');
        return app(match ($account->provider) {
            'linode' => LinodeProvisionClient::class, 'digitalocean' => DigitalOceanProvisionClient::class,
            'vultr' => VultrProvisionClient::class, default => throw new CloudException('unsupported_provider'),
        });
    }
    public function cost(MachineCloudAccount $account): FailoverCostClient
    {
        return app(match ($account->provider) {
            'linode' => LinodeFailoverCostClient::class, 'digitalocean' => DigitalOceanFailoverCostClient::class,
            'vultr' => VultrFailoverCostClient::class, default => throw new CloudException('unsupported_provider'),
        });
    }
    public static function assertProvider(MachineCloudAccount $account, string $expected): void
    {
        if ($account->provider !== $expected) throw new CloudException('provider_mismatch');
    }
    public static function templateSupported(string $provider, string $region, string $size, string $image): bool
    {
        if (!in_array($provider, self::SUPPORTED, true)) return false;
        $prefix = $provider === 'linode' ? 'machine_provision.supported_' : 'machine_provision.providers.' . $provider . '.';
        foreach (['regions' => $region, 'sizes' => $size, 'images' => $image] as $key => $value)
            if (!in_array($value, config($prefix . $key, []), true)) return false;
        return true;
    }
    public static function requestIdentity(string $provider, array $request): array
    {
        $result = ['label' => $request[$provider === 'digitalocean' ? 'name' : 'label'] ?? null,
            'region' => $request['region'] ?? null,
            'size' => $request[match ($provider) { 'linode' => 'type', 'digitalocean' => 'size', 'vultr' => 'plan', default => throw new CloudException('unsupported_provider') }] ?? null,
            'image' => $provider === 'vultr' && is_int($request['os_id'] ?? null) ? (string) $request['os_id'] : ($request['image'] ?? null)];
        foreach ($result as $value) if (!is_string($value) || $value === '' || strlen($value) > 120 || preg_match('/[\x00-\x1F\x7F]/', $value))
            throw new CloudException('provider_invalid');
        return $result;
    }
    public static function cloudInit(array $plan, string $bootstrap): string
    {
        $data = Yaml::parse($bootstrap);
        if (!is_array($data)) throw new CloudException('invalid_bootstrap_identity');
        // Raw public keys are installed in this VM only, never imported into the provider account.
        $data['ssh_pwauth'] = false;
        if ($plan['authorized_keys']) $data['ssh_authorized_keys'] = $plan['authorized_keys'];
        $value = "#cloud-config\n" . Yaml::dump($data, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        if (strlen(base64_encode($value)) > 65535) throw new CloudException('bootstrap_too_large');
        return $value;
    }
    public static function cents(mixed $price): int
    {
        if (!is_numeric($price) || !is_finite((float) $price) || $price < 0 || $price > 10000
            || abs(round((float) $price * 100) - (float) $price * 100) > .000001) throw new CloudException('cost_price_unverified');
        return (int) round((float) $price * 100);
    }
}
