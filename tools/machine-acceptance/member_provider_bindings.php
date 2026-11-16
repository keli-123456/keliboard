<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Services\ServerMachine\Dns\DnsProviderClient;
use Illuminate\Support\Facades\Http;

/** Exact provider operations for a pinned R35 ledger, not an HTTP route or a credential loader. */
final class MemberProviderBindings
{
    public const RUN = 'keli-accept-20261116-r35-f4940b41';
    private \Closure $dnsRead;

    public function __construct(private array $scope, private array $cloud, private array $dns,
        private \Closure $guard, private \Closure $load, private \Closure $save)
    {
        self::need(($scope['run_id'] ?? null) === self::RUN && is_array($scope['pins'] ?? null)
            && is_array($scope['baseline_ids'] ?? null), 'scope_required');
        self::need(array_is_list($scope['baseline_ids']) && count($scope['baseline_ids']) <= 996, 'scope_required');
        foreach ($scope['baseline_ids'] as $id)
            self::need(is_string($id) && preg_match('/^[1-9][0-9]{0,15}$/D', $id) === 1, 'scope_required');
        self::need(is_string($cloud['api_token'] ?? null) && $cloud['api_token'] !== ''
            && is_string($dns['access_key_id'] ?? null) && $dns['access_key_id'] !== ''
            && is_string($dns['access_key_secret'] ?? null) && $dns['access_key_secret'] !== '', 'credentials_required');
        $factory = require __DIR__ . '/aliyun_cleanup.php';
        $this->dnsRead = $factory($dns, function (): int {
            ($this->guard)('dns-read');
            return 10;
        });
    }

    private static function need(bool $value, string $code): void
    {
        if (!$value) throw new \RuntimeException('member_provider_' . $code);
    }

    public static function digest(array $data): string
    {
        $sort = static function (array $value) use (&$sort): array {
            if (!array_is_list($value)) ksort($value);
            foreach ($value as &$v) if (is_array($v)) $v = $sort($v);
            return $value;
        };
        return hash('sha256', json_encode($sort($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function pin(array $pin, string $kind): void
    {
        self::need(($pin['run_id'] ?? null) === self::RUN
            && in_array(self::digest($pin), $this->scope['pins'], true), 'pin_changed');
        if ($kind === 'control') {
            self::need(preg_match('/^[1-9][0-9]{0,15}$/D', $pin['external_id'] ?? '') === 1
                && !in_array($pin['external_id'], $this->scope['baseline_ids'], true)
                && ($pin['label'] ?? null) === self::RUN . '-control'
                && ($pin['marker'] ?? null) === self::RUN . '-owned-control'
                && ($pin['size'] ?? null) === 'g6-standard-2' && ($pin['region'] ?? null) === 'sg-sin-2'
                && ($pin['image'] ?? null) === 'linode/debian12'
                && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ?$/D', $pin['provider_created_at'] ?? '') === 1,
                'cloud_pin_invalid');
        } else {
            $names = ['control-' . self::RUN, '_acme-challenge.control-' . self::RUN,
                'edge-a-' . self::RUN, 'edge-b-' . self::RUN];
            $names = array_map(fn ($n) => $n . '.dnscloudcloud.top', $names);
            self::need(in_array($pin['name'] ?? null, $names, true)
                && ($pin['type'] ?? null) === (str_starts_with($pin['name'], '_acme-challenge.') ? 'TXT' : 'A')
                && ($pin['line'] ?? null) === 'default'
                && preg_match('/^[1-9][0-9]{0,39}$/D', $pin['record_id'] ?? '') === 1
                && is_string($pin['value'] ?? null) && $pin['value'] !== ''
                && is_string($pin['marker'] ?? null) && $pin['marker'] !== '', 'dns_pin_invalid');
        }
    }

    private function cloudRead(array $pin): ?array
    {
        ($this->guard)('cloud-read');
        $r = Http::withToken($this->cloud['api_token'])->acceptJson()->withoutRedirecting()
            ->connectTimeout(4)->timeout(10)->get('https://api.linode.com/v4/linode/instances/' . $pin['external_id']);
        ($this->guard)('cloud-read');
        self::need(strlen($r->body()) <= 262144, 'cloud_read_unconfirmed');
        if ($r->status() === 404) return null;
        $row = $r->json();
        self::need($r->status() === 200 && is_array($row) && !isset($row['errors']), 'cloud_read_unconfirmed');
        self::need(($row['id'] ?? null) === (int) $pin['external_id'], 'cloud_identity_changed');
        foreach (['label' => 'label', 'region' => 'region', 'type' => 'size', 'image' => 'image'] as $remote => $local)
            self::need(($row[$remote] ?? null) === $pin[$local], 'cloud_identity_changed');
        self::need(is_string($row['created'] ?? null)
            && preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ?$/D', $row['created']) === 1
            && rtrim($row['created'], 'Z') === rtrim($pin['provider_created_at'], 'Z')
            && is_array($row['tags'] ?? null) && in_array($pin['marker'], $row['tags'], true), 'cloud_identity_changed');
        return $row;
    }

    private function dnsInspect(array $pin): ?array
    {
        $row = ($this->dnsRead)('DescribeDomainRecordInfo', ['RecordId' => $pin['record_id']], true);
        if ($row === null) return null;
        $expected = ['RecordId' => $pin['record_id'], 'DomainName' => 'dnscloudcloud.top',
            'RR' => substr($pin['name'], 0, -strlen('.dnscloudcloud.top')), 'Type' => $pin['type'],
            'Line' => 'default', 'Value' => $pin['value'], 'Remark' => $pin['marker'], 'TTL' => 600];
        foreach ($expected as $key => $value) self::need(($row[$key] ?? null) === $value, 'dns_identity_changed');
        self::need(($row['Locked'] ?? false) === false, 'dns_identity_changed');
        return $row;
    }

    public function dispatch(string $operation, array $pin): array
    {
        self::need(in_array($operation, ['control-inspect', 'control-delete', 'dns-inspect', 'dns-delete'], true), 'operation_invalid');
        $kind = str_starts_with($operation, 'control-') ? 'control' : 'dns';
        $this->pin($pin, $kind);
        ($this->guard)($operation);
        $hash = self::digest($pin);
        $present = $kind === 'control' ? $this->cloudRead($pin) : $this->dnsInspect($pin);
        $result = ['pin_sha256' => $hash, 'status' => $present === null ? 'absent' : 'owned'];
        if (str_ends_with($operation, '-inspect') || $present === null) return $result;
        $key = 'provider-delete-' . $hash;
        $prior = ($this->load)($key);
        if ($prior !== null) {
            self::need($prior === ['operation' => $operation, 'pin_sha256' => $hash], 'intent_changed');
            return $result + ['reconciliation_required' => true, 'dispatched' => false];
        }
        // Persist before dispatch, and recheck the exact object after the journal write.
        ($this->save)($key, ['operation' => $operation, 'pin_sha256' => $hash]);
        $present = $kind === 'control' ? $this->cloudRead($pin) : $this->dnsInspect($pin);
        if ($present === null) return ['pin_sha256' => $hash, 'status' => 'absent', 'dispatched' => false];
        ($this->guard)($operation);
        if ($kind === 'control') {
            $r = Http::withToken($this->cloud['api_token'])->acceptJson()->withoutRedirecting()->connectTimeout(4)->timeout(10)
                ->delete('https://api.linode.com/v4/linode/instances/' . $pin['external_id']);
            self::need(strlen($r->body()) <= 262144 && ($r->status() === 204
                || $r->status() === 200 && is_array($r->json()) && !array_key_exists('errors', $r->json())), 'delete_unconfirmed');
        } else {
            $parameters = DnsProviderClient::signAliyun(['Action' => 'DeleteDomainRecord', 'RecordId' => $pin['record_id'],
                'Format' => 'JSON', 'Version' => '2015-01-09', 'AccessKeyId' => $this->dns['access_key_id'],
                'SignatureMethod' => 'HMAC-SHA1', 'SignatureVersion' => '1.0', 'SignatureNonce' => bin2hex(random_bytes(16)),
                'Timestamp' => gmdate('Y-m-d\TH:i:s\Z')], $this->dns['access_key_secret']);
            $r = Http::asForm()->acceptJson()->withoutRedirecting()->connectTimeout(4)->timeout(10)
                ->post('https://alidns.aliyuncs.com/', $parameters);
            self::need($r->successful() && strlen($r->body()) <= 262144 && is_array($r->json())
                && !array_key_exists('Code', $r->json()) && $r->json('RecordId') === $pin['record_id'], 'delete_unconfirmed');
        }
        // An ACK is never an absence read. The outer lifecycle requires two later reads.
        return $result + ['dispatched' => true];
    }
}
