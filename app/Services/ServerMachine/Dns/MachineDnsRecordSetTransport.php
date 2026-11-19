<?php
namespace App\Services\ServerMachine\Dns;

interface MachineDnsRecordSetTransport
{
    public function inventory(string $run, array $set): array;
    public function write(string $run, array $set, array $action, ?string $knownId = null): string;
}
