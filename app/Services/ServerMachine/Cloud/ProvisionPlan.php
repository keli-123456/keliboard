<?php
namespace App\Services\ServerMachine\Cloud;

use phpseclib3\Crypt\PublicKeyLoader;

final class ProvisionPlan
{
    public static function normalize(array $input): array
    {
        $allowed = ['account_id', 'machine_id', 'slot', 'panel_origin', 'region', 'size', 'image', 'authorized_keys', 'monthly_limit_usd', 'source_ref', 'provider', 'credential_pool'];
        if (array_diff(array_keys($input), $allowed)) throw new CloudException('invalid_plan');
        if (!is_string($input['panel_origin'] ?? null)) throw new CloudException('invalid_panel_origin');
        foreach (['account_id', 'machine_id'] as $key) {
            if (!is_int($input[$key] ?? null) || $input[$key] < 1) throw new CloudException('invalid_plan');
        }
        $provider = $input['provider'] ?? 'linode';
        if (!in_array($provider, ['linode', 'digitalocean', 'vultr'], true)) throw new CloudException('unsupported_provider');
        foreach (['slot' => '/^[a-z0-9:_-]{1,80}$/D', 'region' => '/^[a-z0-9-]{1,60}$/D',
            'size' => '/^[a-z0-9-]{1,60}$/D', 'image' => $provider === 'linode' ? '~^linode/[a-z0-9-]{1,60}$~D' : '/^[a-z0-9-]{1,80}$/D'] as $key => $pattern) {
            if (!is_string($input[$key] ?? null) || !preg_match($pattern, $input[$key])) throw new CloudException('invalid_plan');
        }
        $keys = $input['authorized_keys'] ?? [];
        if (!is_array($keys) || !array_is_list($keys) || count($keys) > 8) throw new CloudException('invalid_ssh_key');
        foreach ($keys as $key) {
            if (!is_string($key) || strlen($key) > 8192 || !preg_match('/^(?:ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp256) [A-Za-z0-9+\/=]+(?: [^\r\n]*)?$/D', $key)) throw new CloudException('invalid_ssh_key');
            try { PublicKeyLoader::loadPublicKey($key); } catch (\Throwable) { throw new CloudException('invalid_ssh_key'); }
        }
        $limit = $input['monthly_limit_usd'] ?? 0;
        if (!is_numeric($limit) || !is_finite((float) $limit) || $limit < 0 || $limit > 1000) throw new CloudException('invalid_budget');
        $source = $input['source_ref'] ?? [];
        if (!is_array($source) || array_diff(array_keys($source), ['version', 'service_id', 'member_id', 'script_id', 'script_sha256'])) throw new CloudException('invalid_source_ref');
        if ($source) {
            if (!in_array($source['version'] ?? null, ['v1', 'v2'], true)) throw new CloudException('invalid_source_ref');
            foreach (['service_id', 'member_id', 'script_id'] as $field) if (isset($source[$field]) && (!is_int($source[$field]) || $source[$field] < 1)) throw new CloudException('invalid_source_ref');
            if (isset($source['script_sha256']) && (!is_string($source['script_sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $source['script_sha256']))) throw new CloudException('invalid_source_ref');
        }
        $scope = [];
        if (isset($input['credential_pool'])) {
            $pool = $input['credential_pool'];
            if (!is_array($pool) || array_diff(array_keys($pool), ['group_id', 'provider', 'fingerprint', 'account_revision'])
                || !is_int($pool['group_id'] ?? null) || $pool['group_id'] < 1 || ($pool['provider'] ?? null) !== $provider
                || !is_int($pool['account_revision'] ?? null) || $pool['account_revision'] < 1
                || !is_string($pool['fingerprint'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $pool['fingerprint'])) throw new CloudException('invalid_plan');
            $scope = ['provider' => $provider, 'credential_pool' => $pool];
        } elseif (isset($input['provider'])) $scope = ['provider' => $provider];
        return $scope + ['account_id' => $input['account_id'], 'machine_id' => $input['machine_id'], 'slot' => $input['slot'],
            'panel_origin' => app(ProvisionBootstrap::class)->origin((string) ($input['panel_origin'] ?? '')),
            'region' => $input['region'], 'size' => $input['size'], 'image' => $input['image'],
            'authorized_keys' => array_values(array_unique($keys)), 'monthly_limit_usd' => (float) $limit, 'source_ref' => $source];
    }

    public static function summary(array $plan): array
    {
        $result = $plan; unset($result['authorized_keys']);
        return $result + ['authorized_key_count' => count($plan['authorized_keys']),
            'root_password_mode' => ($plan['provider'] ?? 'linode') === 'linode' ? 'random' : 'provider_generated_ssh_password_disabled',
            'delete_strategy' => 'keep', 'dns_cutover' => false, 'automatic' => false, 'initialization' => 'cloud_init_kelinode_rs',
            'script_review_required' => $plan['source_ref'] !== []];
    }
}
