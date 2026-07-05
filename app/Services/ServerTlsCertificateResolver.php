<?php

namespace App\Services;

use App\Models\Server;
use App\Models\ServerTlsCertificate;
use Illuminate\Support\Facades\Schema;

class ServerTlsCertificateResolver
{
    public function resolvePinnedPeerCertSha256(array|Server $server): ?string
    {
        $serverId = (int) data_get($server, 'id', 0);
        if ($serverId <= 0 || !$this->hasCertificateTable()) {
            return null;
        }

        $sni = $this->normalizeSni($this->resolveServerName($server));
        $query = ServerTlsCertificate::query()
            ->where('server_id', $serverId)
            ->where('protocol', 'hysteria2')
            ->where('status', 'valid')
            ->whereNotNull('sha256_base64')
            ->where('sha256_base64', '!=', '');

        if ($sni !== '') {
            $query->where(function ($query) use ($sni): void {
                $query->where('sni', $sni)->orWhereNull('sni')->orWhere('sni', '');
            });
        }

        $record = $query
            ->orderByRaw("CASE WHEN sni = ? THEN 0 ELSE 1 END", [$sni])
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->first();

        return $record?->sha256_base64 ?: null;
    }

    private function resolveServerName(array|Server $server): string
    {
        $settings = data_get($server, 'protocol_settings');
        if (!is_array($settings)) {
            $settings = [];
        }

        return trim((string) (
            data_get($settings, 'tls.server_name')
            ?: data_get($settings, 'tls_settings.server_name')
            ?: data_get($settings, 'server_name')
            ?: ''
        ));
    }

    private function normalizeSni(string $sni): string
    {
        return mb_strtolower(trim($sni));
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
