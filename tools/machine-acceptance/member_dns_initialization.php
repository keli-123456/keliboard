<?php
declare(strict_types=1);

namespace Keli\Acceptance;

use App\Models\MachineDnsCredential;
use App\Services\ServerMachine\Dns\DnsProviderClient;
use App\Services\ServerMachine\Dns\DnsException;

/** Exact fresh A-record setup; caller holds the controller's exclusive journal lock. */
final class MemberDnsInitialization
{
    public function __construct(private array $scope, private MachineDnsCredential $credential,
        private \Closure $guard, private \Closure $load, private \Closure $save)
    {
        self::need(($scope['run_id'] ?? null) === MemberProviderBindings::RUN
            && count($scope) === 4 && !array_diff(array_keys($scope), ['run_id', 'credential_id', 'credential_revision', 'records'])
            && $credential->provider === 'aliyun' && is_int($credential->id) && $credential->id > 0
            && ($scope['credential_id'] ?? null) === $credential->id && ($scope['credential_revision'] ?? null) === $credential->revision
            && is_array($scope['records'] ?? null) && $scope['records'] !== []
            && !array_diff(array_keys($scope['records']), ['control', 'edge-a', 'edge-b']), 'scope_invalid');
        foreach ($scope['records'] as $role => $record) {
            self::need(is_array($record) && count($record) === 2 && !array_diff(array_keys($record), ['value', 'marker'])
                && is_string($record['value']) && filter_var($record['value'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
                && \App\Services\ServerMachine\MachineEntryProbe::publicIp($record['value']) === $record['value']
                && is_string($record['marker']) && preg_match('/^keli-set-[a-f0-9]{48}$/D', $record['marker']), 'record_invalid');
        }
        self::need(count(array_unique(array_column($scope['records'], 'marker'))) === count($scope['records']), 'marker_reused');
    }

    private static function need(bool $value, string $code): void
    {
        if (!$value) throw new \RuntimeException('member_dns_initialization_' . $code);
    }

    public static function key(string $name): string { return 'dns-initialize-' . hash('sha256', $name); }

    private function request(string $role): array
    {
        self::need(isset($this->scope['records'][$role]), 'role_invalid');
        return ['run_id' => $this->scope['run_id'], 'credential_id' => $this->credential->id,
            'credential_revision' => $this->credential->revision, 'name' => $role . '-' . $this->scope['run_id'] . '.dnscloudcloud.top',
            'type' => 'A', 'line' => 'default', 'ttl' => 600] + $this->scope['records'][$role];
    }

    private function set(array $request): array
    {
        return ['provider' => 'aliyun', 'zone' => 'dnscloudcloud.top', 'hostname' => $request['name'], 'ttl' => 600,
            'credential_id' => $this->credential->id, 'credential_revision' => $this->credential->revision];
    }

    private function persist(string $key, array $state): void
    {
        ($this->save)($key, $state);
        self::need(($this->load)($key) === $state, 'journal_unconfirmed');
    }

    private function state(array $request): ?array
    {
        $state = ($this->load)(self::key($request['name']));
        self::need($state === null || is_array($state) && is_array($state['request'] ?? null)
            && MemberProviderBindings::digest($state['request']) === MemberProviderBindings::digest($request)
            && count($state) === 6 && !array_diff(array_keys($state), ['request', 'create_sent', 'created_id', 'claim_sent', 'rejected', 'completed'])
            && is_bool($state['create_sent']) && is_bool($state['claim_sent']) && is_bool($state['rejected'])
            && is_bool($state['completed']) && ($state['created_id'] === null
                || is_string($state['created_id']) && preg_match('/^[1-9][0-9]{0,39}$/D', $state['created_id']))
            && ($state['created_id'] === null || $state['create_sent'])
            && (!$state['claim_sent'] || $state['created_id'] !== null)
            && (!$state['completed'] || $state['claim_sent'])
            && (!$state['rejected'] || $state['create_sent'] && $state['created_id'] === null && !$state['claim_sent']), 'journal_changed');
        return $state;
    }

    private function inventory(array $request): array
    {
        ($this->guard)('dns-read', $request);
        $rows = app(DnsProviderClient::class)->recordSetInventory($this->credential, $this->set($request));
        ($this->guard)('dns-read', $request);
        return $rows;
    }

    private function owned(array $request, array $state, array $rows): ?array
    {
        self::need(count($rows) <= 1, 'foreign_record');
        if (!$rows) return null;
        $row = $rows[0];
        self::need($state['created_id'] !== null && $row['id'] === $state['created_id']
            && $row['hostname'] === $request['name'] && $row['type'] === 'A' && $row['line'] === 'default'
            && $row['content'] === $request['value'] && $row['ttl'] === 600
            && $row['proxied'] === false && $row['locked'] === false
            && in_array($row['marker'], ['', $request['marker']], true), 'foreign_record');
        self::need(!$state['completed'] || $row['marker'] === $request['marker'], 'ownership_changed');
        return $row;
    }

    private function pin(array $request, array $state): array
    {
        self::need($state['created_id'] !== null && $state['create_sent'] && !$state['rejected'], 'create_unconfirmed');
        return ['run_id' => $request['run_id'], 'record_id' => $state['created_id'], 'name' => $request['name'],
            'type' => 'A', 'line' => 'default', 'value' => $request['value'], 'marker' => $request['marker']];
    }

    public function advance(string $role): array
    {
        $request = $this->request($role); $key = self::key($request['name']); $state = $this->state($request);
        ($this->guard)('dns-initialize', $request);
        $rows = $this->inventory($request);
        if ($state === null) {
            self::need($rows === [], 'name_not_fresh');
            $state = ['request' => $request, 'create_sent' => false, 'created_id' => null,
                'claim_sent' => false, 'rejected' => false, 'completed' => false];
            $this->persist($key, $state);
        }
        self::need(!$state['rejected'], 'create_rejected');
        if (!$state['create_sent']) {
            self::need($rows === [], 'name_not_fresh');
            ($this->guard)('dns-create', $request);
            // Recheck freshness after durable intent and before the one allowed POST.
            self::need($this->inventory($request) === [], 'name_not_fresh');
            $state['create_sent'] = true; $this->persist($key, $state);
            try {
                ($this->guard)('dns-create', $request);
                $state['created_id'] = app(DnsProviderClient::class)->recordSetWrite($this->credential, $this->set($request),
                    ['kind' => 'create', 'after' => ['type' => 'A', 'line' => 'default', 'content' => $request['value'], 'marker' => $request['marker']]]);
                $this->persist($key, $state);
            } catch (DnsException $error) {
                $d = $error->providerDiagnostic();
                if (($d['action'] ?? null) === 'AddDomainRecord' && in_array($d['http_status'] ?? null, [400, 403], true)
                    && in_array($d['code'] ?? null, ['Forbidden', 'Forbidden.RAM', 'Forbidden.RAMPolicy',
                        'InvalidAccessKeyId.NotFound', 'SignatureDoesNotMatch', 'InvalidParameter', 'InvalidParameterValue'], true)) {
                    $state['rejected'] = true; $this->persist($key, $state);
                }
                throw $error;
            }
            $rows = $this->inventory($request);
        }
        self::need($state['created_id'] !== null, 'create_unconfirmed');
        $row = $this->owned($request, $state, $rows);
        self::need($row !== null, 'created_record_missing');
        if ($row['marker'] !== $request['marker']) {
            self::need(!$state['claim_sent'] && !$state['completed'], 'claim_unconfirmed');
            ($this->guard)('dns-claim', $request);
            $state['claim_sent'] = true; $this->persist($key, $state);
            $row = $this->owned($request, $state, $this->inventory($request));
            self::need($row !== null && $row['marker'] === '', 'claim_identity_changed');
            ($this->guard)('dns-claim', $request);
            app(DnsProviderClient::class)->recordSetWrite($this->credential, $this->set($request),
                ['kind' => 'claim', 'before' => $row, 'after' => array_replace($row, ['marker' => $request['marker']])], $state['created_id']);
            $row = $this->owned($request, $state, $this->inventory($request));
        }
        self::need($state['claim_sent'] && $row !== null && $row['marker'] === $request['marker'], 'claim_unconfirmed');
        $state['completed'] = true; $this->persist($key, $state);
        return ['ready' => true, 'pin' => $this->pin($request, $state)];
    }

    public function readback(string $role): array
    {
        $request = $this->request($role); $state = $this->state($request);
        if ($state === null) return ['resolved' => true, 'pin' => null, 'ready' => false];
        $rows = $this->inventory($request);
        if ($state['created_id'] === null) {
            self::need(!$state['create_sent'] || $state['rejected'], 'create_unconfirmed');
            self::need($rows === [], 'foreign_record');
            return ['resolved' => true, 'pin' => null, 'ready' => false];
        }
        $row = $this->owned($request, $state, $rows);
        return ['resolved' => true, 'pin' => $this->pin($request, $state),
            'ready' => $row !== null && $state['claim_sent'] && $row['marker'] === $request['marker']];
    }

    public function cleanupReceipt(string $role): ?array
    {
        $request = $this->request($role); $state = $this->state($request);
        if ($state === null) return null;
        if ($state['created_id'] === null) {
            self::need(!$state['create_sent'] || $state['rejected'], 'create_unconfirmed');
            return null;
        }
        return $this->pin($request, $state);
    }

    /** Permit blank remarks only for the exact ID in our acknowledged create journal. */
    public static function hasCreateReceipt(array $pin, \Closure $load): bool
    {
        $state = $load(self::key($pin['name'])); $r = $state['request'] ?? [];
        return is_array($state) && ($state['create_sent'] ?? null) === true && ($state['rejected'] ?? null) === false
            && ($state['completed'] ?? null) === false
            && ($state['created_id'] ?? null) === $pin['record_id'] && ($r['ttl'] ?? null) === 600
            && MemberProviderBindings::digest(array_intersect_key($r, array_flip(['run_id', 'name', 'type', 'line', 'value', 'marker'])))
                === MemberProviderBindings::digest(array_diff_key($pin, ['record_id' => true]));
    }
}
