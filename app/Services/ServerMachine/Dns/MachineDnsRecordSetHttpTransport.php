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
    private function credential(array $set): MachineDnsCredential
    {
        $row = MachineDnsCredential::query()->find($set['credential_id']);
        if (!$row || $row->revision !== $set['credential_revision'] || $row->provider !== $set['provider']) throw new CloudException('dns_credential_changed');
        return $row;
    }
}
