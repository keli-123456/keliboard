<?php
namespace App\Services\ServerMachine\Dns;

use App\Models\MachineDnsCredential;
use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;

class MachineDnsRecordSetHttpTransport implements MachineDnsRecordSetTransport
{
    public function __construct(private DnsProviderClient $client, private MachineFailoverExecutionGate $gate) {}
    public function inventory(string $run, array $set): array
    {
        $this->gate->assertAllowed('dns_set_read');
        return $this->client->recordSetInventory($this->credential($set), $set);
    }
    public function write(string $run, array $set, array $action, ?string $knownId = null): string
    {
        $this->gate->assertAllowed('dns_set_write');
        return $this->client->recordSetWrite($this->credential($set), $set, $action, $knownId);
    }
    public function verifyTtl(array $set, array $wire): void
    {
        if ($set['provider'] !== 'aliyun' || ($wire['parameters']['TTL'] ?? DnsProviderClient::ALIYUN_STANDARD_TTL) >= DnsProviderClient::ALIYUN_STANDARD_TTL) return;
        $this->gate->assertAllowed('dns_set_write');
        $minimum = $this->client->aliyunDomainMinTtl($this->credential($set), $set['zone']);
        if ($minimum === null) throw new DnsException('dns_ttl_unverified');
        if ($wire['parameters']['TTL'] < $minimum) throw new DnsException('dns_ttl_below_minimum');
    }
    private function credential(array $set): MachineDnsCredential
    {
        $row = MachineDnsCredential::query()->find($set['credential_id']);
        if (!$row || $row->revision !== $set['credential_revision'] || $row->provider !== $set['provider']) throw new CloudException('dns_credential_changed');
        return $row;
    }
}
