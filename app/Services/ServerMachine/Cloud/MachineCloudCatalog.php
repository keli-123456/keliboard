<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount;
use Illuminate\Support\Facades\Cache;

class MachineCloudCatalog
{
    public function read(int $group, string $provider, bool $refresh = false): array
    {
        return app(MachineCloudGroupService::class)->withGroup($group, function () use ($group, $provider, $refresh) {
            $pool = app(MachineFailoverCredentials::class)->pool($group, $provider);
            $key = 'machine-cloud:catalog:' . $pool['fingerprint'];
            $cached = Cache::get($key);
            if (!$refresh && is_array($cached) && ($cached['expires_at'] ?? 0) > time()) return $cached;
            Cache::forget($key);
            $account = MachineCloudAccount::query()->findOrFail($pool['accounts']->first()->id);
            $client = match ($provider) { 'linode' => app(LinodeClient::class), 'digitalocean' => app(DigitalOceanClient::class), 'vultr' => app(VultrClient::class) };
            $options = $client->catalog($account);
            if (app(MachineFailoverCredentials::class)->pool($group, $provider)['fingerprint'] !== $pool['fingerprint']) throw new CloudException('credential_group_changed');
            $result = ['provider' => $provider, 'credential_group_id' => $group, 'pool_fingerprint' => $pool['fingerprint'],
                'source_account_id' => $account->id, 'checked_at' => time(), 'expires_at' => time() + 600,
                'creation_provider_supported' => $provider === 'linode', 'inventory_guaranteed' => false] + $options;
            $result['fingerprint'] = hash('sha256', json_encode($result, JSON_THROW_ON_ERROR));
            Cache::put($key, $result, 600); return $result;
        });
    }

    public function validate(array $input): array
    {
        $pool = app(MachineFailoverCredentials::class)->pool($input['credential_group_id'], $input['provider']);
        $catalog = Cache::get('machine-cloud:catalog:' . $pool['fingerprint']);
        if (!is_array($catalog) || ($catalog['expires_at'] ?? 0) <= time() || ($catalog['fingerprint'] ?? null) !== $input['catalog_fingerprint']) throw new CloudException('catalog_expired');
        $regions = array_column($catalog['regions'], null, 'id'); $sizes = array_column($catalog['sizes'], null, 'id'); $images = array_column($catalog['images'], null, 'id');
        $size = $sizes[$input['size']] ?? null; $image = $images[$input['image']] ?? null;
        if (!isset($regions[$input['region']]) || !$size || !$image || !in_array($input['region'], $size['regions'], true)
            || !in_array($input['region'], $image['regions'], true) || $size['disk_gib'] < $image['min_disk_gib']) throw new CloudException('provider_template_mismatch');
        return $catalog;
    }
}
