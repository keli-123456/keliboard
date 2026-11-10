<?php
namespace App\Services\ServerMachine\Cloud;

use Illuminate\Support\Facades\Http;

/** The configured worker owns isolated test profiles and must use real protocol clients. */
class MachineForwardingVerifier
{
    public function configuration(int $policy): array
    {
        $url = config('machine_acceptance.verifier_url'); $key = config('machine_acceptance.verifier_key');
        $profile = config('machine_acceptance.verifier_profiles.' . $policy);
        $parts = is_string($url) ? parse_url($url) : false;
        if (!$parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || !is_string($key) || strlen($key) < 32
            || !is_string($profile) || !preg_match('/^[a-z0-9_-]{1,64}$/D', $profile)) throw new CloudException('verifier_not_configured');
        return ['url' => $url, 'key' => $key, 'profile' => $profile,
            'fingerprint' => hash('sha256', $url . ':' . hash('sha256', $key) . ':' . $profile)];
    }

    public function verify(array $challenge, array $configuration): array
    {
        $body = json_encode($challenge, JSON_THROW_ON_ERROR);
        try {
            $response = Http::withHeaders(['X-Keli-Signature' => hash_hmac('sha256', "request\n" . $body, $configuration['key'])])
                ->acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(30)->withBody($body, 'application/json')->post($configuration['url']);
        } catch (\Throwable) { throw new CloudException('verifier_unavailable'); }
        $raw = $response->body(); $signature = $response->header('X-Keli-Signature');
        if (!$response->successful() || strlen($raw) > 32768 || !is_string($signature)
            || !preg_match('/^[a-f0-9]{64}$/D', $signature) || !hash_equals(hash_hmac('sha256', "response\n" . $raw, $configuration['key']), $signature)) throw new CloudException('verification_invalid');
        $data = json_decode($raw, true);
        $schema = $challenge['schema'] ?? null;
        if (!in_array($schema, [1, 2], true) || !is_array($data) || ($data['schema'] ?? null) !== $schema || ($data['challenge_id'] ?? null) !== $challenge['challenge_id']
            || ($data['request_sha256'] ?? null) !== hash('sha256', $body) || ($data['cleanup_complete'] ?? null) !== true
            || !is_int($data['finished_at'] ?? null) || $data['finished_at'] < $challenge['issued_at'] || $data['finished_at'] > time() + 3
            || time() > $challenge['deadline_at'] || !is_array($data['results'] ?? null) || !array_is_list($data['results'])
            || count($data['results']) !== count($challenge['nodes'])) throw new CloudException('verification_invalid');
        if ($schema === 2) {
            if (!in_array($challenge['address_family'] ?? null, ['A', 'AAAA'], true)) throw new CloudException('verification_invalid');
            foreach (['address_family', 'target_ip', 'task_id', 'installation_id', 'attempt_id', 'native_context_sha256',
                'source_installation_id', 'session', 'binary_sha256', 'configuration_fingerprint'] as $field)
                if (($data[$field] ?? null) !== ($challenge[$field] ?? null)) throw new CloudException('verification_identity_mismatch');
        }
        $results = [];
        foreach ($challenge['nodes'] as $index => $node) {
            $result = $data['results'][$index] ?? [];
            if (($result['node_id'] ?? null) !== $node['id'] || ($result['endpoint_authenticated'] ?? null) !== true) throw new CloudException('forwarding_failed');
            foreach (['tcp', 'udp'] as $transport) {
                $sample = $result[$transport] ?? [];
                if (($sample['sent'] ?? null) !== 3 || ($sample['received'] ?? null) !== 3 || ($sample['missing'] ?? null) !== 0
                    || ($sample['duplicates'] ?? null) !== 0 || ($sample['bytes'] ?? null) !== 96
                    || ($sample['sha256'] ?? null) !== $challenge['echo_sha256']) throw new CloudException('forwarding_failed');
            }
            $results[] = ['node_id' => $node['id'], 'tcp_echoes' => 3, 'udp_echoes' => 3];
        }
        return ['finished_at' => $data['finished_at'], 'receipt_sha256' => hash('sha256', $raw), 'results' => $results,
            'cleanup_complete' => true, 'verifier_fingerprint' => $configuration['fingerprint']]
            + ($schema === 2 ? array_intersect_key($challenge, array_flip(['address_family', 'target_ip', 'task_id', 'installation_id',
                'attempt_id', 'native_context_sha256', 'source_installation_id', 'session', 'binary_sha256', 'configuration_fingerprint'])) : []);
    }
}
