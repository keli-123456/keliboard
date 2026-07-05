<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\ServerTlsCertificate;
use Illuminate\Support\Facades\Schema;

class ServerTlsCertificateService
{
    private const VALID_STATUSES = ['valid', 'missing', 'invalid', 'stale'];

    public function handleMachineStatus(ServerMachine $machine, array $status): array
    {
        $rows = data_get($status, 'tls_certificates');
        if (!is_array($rows) || !$this->hasCertificateTable()) {
            return ['changed' => false, 'stored' => 0, 'skipped' => 0];
        }

        $changed = false;
        $stored = 0;
        $skipped = 0;

        foreach (array_slice(array_values($rows), 0, 200) as $row) {
            if (!is_array($row)) {
                $skipped++;
                continue;
            }

            $normalized = $this->normalizeRow($machine, $row);
            if ($normalized === null) {
                $skipped++;
                continue;
            }

            $server = Server::query()
                ->whereKey($normalized['server_id'])
                ->where('machine_id', (int) $machine->id)
                ->first();

            if (!$server || !$this->isHysteria2Server($server)) {
                $skipped++;
                continue;
            }

            $result = $this->storeRow($normalized);
            $stored += $result['stored'];
            $skipped += $result['skipped'];
            $changed = $changed || $result['changed'];
        }

        return ['changed' => $changed, 'stored' => $stored, 'skipped' => $skipped];
    }

    private function normalizeRow(ServerMachine $machine, array $row): ?array
    {
        $serverId = (int) data_get($row, 'node_id', 0);
        if ($serverId <= 0) {
            return null;
        }

        $protocol = mb_strtolower(trim((string) data_get($row, 'protocol', '')));
        if ($protocol === 'hysteria') {
            $protocol = 'hysteria2';
        }
        if ($protocol !== 'hysteria2') {
            return null;
        }

        $status = mb_strtolower(trim((string) data_get($row, 'status', '')));
        if (!in_array($status, self::VALID_STATUSES, true)) {
            $status = 'invalid';
        }

        $sha256Hex = $this->normalizeSha256Hex(data_get($row, 'sha256_hex'));
        if ($status === 'valid' && $sha256Hex === null) {
            return null;
        }

        return [
            'server_id' => $serverId,
            'machine_id' => (int) $machine->id,
            'protocol' => $protocol,
            'sni' => $this->normalizeSni(data_get($row, 'sni')),
            'status' => $status,
            'sha256_hex' => $sha256Hex,
            'sha256_base64' => $sha256Hex ? base64_encode(hex2bin($sha256Hex)) : null,
        ];
    }

    private function storeRow(array $row): array
    {
        $record = ServerTlsCertificate::query()
            ->where('server_id', $row['server_id'])
            ->where('machine_id', $row['machine_id'])
            ->where('protocol', $row['protocol'])
            ->where('sni', $row['sni'])
            ->first();

        if ($record && $record->status === 'valid' && $row['status'] !== 'valid') {
            return ['changed' => false, 'stored' => 0, 'skipped' => 1];
        }

        if (!$record) {
            $record = new ServerTlsCertificate([
                'server_id' => $row['server_id'],
                'machine_id' => $row['machine_id'],
                'protocol' => $row['protocol'],
                'sni' => $row['sni'],
            ]);
        }

        $updates = [
            'status' => $row['status'],
            'sha256_hex' => $row['sha256_hex'],
            'sha256_base64' => $row['sha256_base64'],
        ];

        $hasChanged = !$record->exists;
        foreach ($updates as $key => $value) {
            if ((string) ($record->{$key} ?? '') !== (string) ($value ?? '')) {
                $hasChanged = true;
                break;
            }
        }

        if (!$hasChanged) {
            return ['changed' => false, 'stored' => 0, 'skipped' => 1];
        }

        $record->forceFill($updates + [
            'reported_at' => now(),
            'changed_at' => now(),
        ])->save();

        return ['changed' => true, 'stored' => 1, 'skipped' => 0];
    }

    private function isHysteria2Server(Server $server): bool
    {
        if ((string) $server->type !== Server::TYPE_HYSTERIA) {
            return false;
        }

        $settings = is_array($server->protocol_settings) ? $server->protocol_settings : [];

        return (int) data_get($settings, 'version', 2) === 2;
    }

    private function normalizeSha256Hex(mixed $value): ?string
    {
        $hex = mb_strtolower(preg_replace('/[^a-fA-F0-9]/', '', (string) $value) ?? '');

        return preg_match('/^[a-f0-9]{64}$/', $hex) ? $hex : null;
    }

    private function normalizeSni(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    private function hasCertificateTable(): bool
    {
        try {
            return Schema::hasTable('v2_server_tls_certificate');
        } catch (\Throwable) {
            return false;
        }
    }
}
