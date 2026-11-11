<?php
namespace App\Services\ServerMachine\Dns;

use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use Illuminate\Support\Facades\Http;

class MachineDnsPropagationHttp
{
    private const ENDPOINTS = ['cloudflare' => 'https://cloudflare-dns.com/dns-query', 'google' => 'https://dns.google/resolve'];
    public function __construct(private MachineFailoverExecutionGate $gate) {}
    public function query(string $resolver, string $hostname, string $type): array
    {
        $this->gate->assertAllowed('dns_external_read');
        $name = MachineDnsPropagation::hostname($hostname);
        if (!isset(self::ENDPOINTS[$resolver]) || !in_array($type, ['A', 'AAAA'], true)
            || preg_match('/\.(?:localhost|local|internal|test|invalid|example)$/D', $name)) throw new CloudException('invalid_dns_propagation');
        $result = ['resolver' => $resolver, 'type' => $type, 'checked_at' => time(), 'status' => 'invalid'];
        try {
            $query = ['name' => $name, 'type' => $type, 'cd' => '0'];
            if ($resolver === 'google') $query['edns_client_subnet'] = '0.0.0.0/0';
            $response = Http::withHeaders(['Accept' => 'application/dns-json', 'Cache-Control' => 'no-cache'])->withoutRedirecting()->connectTimeout(2)->timeout(4)
                ->get(self::ENDPOINTS[$resolver], $query);
            $result['checked_at'] = time();
            if ($response->status() !== 200 || strlen($response->body()) > 65536 || !is_array($response->json())) return $result;
            return array_replace($result, ['status' => 'ok', 'body' => $response->json()]);
        } catch (\Throwable) { return array_replace($result, ['checked_at' => time(), 'status' => 'timeout']); }
    }
}
