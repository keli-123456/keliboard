<?php
declare(strict_types=1);

use App\Models\MachineDnsCredential;
use App\Services\ServerMachine\Cloud\MachineFailoverExecutionGate;
use App\Services\ServerMachine\Dns\DnsException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Keli\Acceptance\MemberDnsInitialization;
use Keli\Acceptance\MemberProviderBindings;

// Only the full-application fixture loads this function. Provider I/O is simulated;
// encrypted Eloquent storage, Redis and journal files use the actual application.
return static function (string $mode): array {
    if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('KELI_MEMBER_FULL_APPLICATION') !== '1'
        || PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli' || posix_geteuid() !== 0)
        throw new RuntimeException('fixture_only');
    require_once '/opt/keli-isolated/runtime/member_provider_bindings.php';
    require_once '/opt/keli-isolated/runtime/member_dns_initialization.php';
    $root = '/opt/keli-isolated/private/dns-fixture';
    $need = static function (bool $value): void { if (!$value) throw new RuntimeException('fixture_dns_invalid'); };
    if ($mode === 'dns-prepare') {
        $need(!file_exists($root)); mkdir($root, 0700);
    }
    $need(realpath($root) === $root && (fileperms($root) & 0077) === 0);
    $lock = fopen($root . '/lock', 'c+b');
    $need($lock !== false && flock($lock, LOCK_EX | LOCK_NB));
    chmod($root . '/lock', 0600);
    $load = static function (string $key) use ($root, $need): ?array {
        $need(preg_match('/^[a-z0-9-]{1,100}$/D', $key) === 1);
        $path = $root . '/' . $key . '.json';
        if (!file_exists($path)) return null;
        $need(realpath($path) === $path && (fileperms($path) & 0077) === 0);
        $value = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        $need(is_array($value)); return $value;
    };
    $save = static function (string $key, array $value) use ($root, $need): void {
        $need(preg_match('/^[a-z0-9-]{1,100}$/D', $key) === 1);
        $temporary = $root . '/.' . bin2hex(random_bytes(12));
        $stream = fopen($temporary, 'x+b'); $need($stream !== false); chmod($temporary, 0600);
        try {
            $raw = json_encode($value, JSON_THROW_ON_ERROR);
            $need(fwrite($stream, $raw) === strlen($raw) && fflush($stream) && fsync($stream));
        } finally { fclose($stream); }
        $need(rename($temporary, $root . '/' . $key . '.json'));
    };
    try {
        $closed = \Keli\Acceptance\MemberCliAuthority::closed();
        foreach ($closed as $key => $value) $need(config($key) === $value);
        $cacheKey = 'dns-initialization-fixture';
        if ($mode === 'dns-prepare') {
            $need(!MachineDnsCredential::query()->exists());
            $credential = MachineDnsCredential::query()->create(['name' => 'r35-fixture', 'provider' => 'aliyun',
                'revision' => 1, 'credentials' => ['access_key_id' => 'fixture-id', 'access_key_secret' => 'fixture-secret']]);
            $save('fixture', ['credential_id' => $credential->id, 'rows' => [], 'writes' => [], 'stopped' => false]);
            Cache::put($cacheKey, 0, 600);
        }
        $fixture = $load('fixture'); $need(is_array($fixture));
        $credential = MachineDnsCredential::query()->findOrFail($fixture['credential_id']);
        $need($credential->credentials['access_key_secret'] === 'fixture-secret');
        $stored = DB::table('v2_machine_dns_credential')->where('id', $credential->id)->value('credentials');
        $need(is_string($stored) && !str_contains($stored, 'fixture-secret'));
        $need(Cache::increment($cacheKey) >= 1);
        $guard = static function (string $operation) use (&$fixture): void {
            if ($fixture['stopped'] && !in_array($operation, ['dns-read', 'dns-inspect', 'dns-delete'], true))
                throw new RuntimeException('fixture_dns_stopped');
        };
        app()->instance(MachineFailoverExecutionGate::class, new class extends MachineFailoverExecutionGate {
            public function assertAllowed(string $operation): void {
                if (!in_array($operation, ['dns_set_read', 'dns_set_write'], true)) throw new RuntimeException('fixture_dns_gate');
            }
        });
        Http::preventStrayRequests();
        Http::fake(static function ($request) use (&$fixture, $save, $mode, $need) {
            $need($request->url() === 'https://alidns.aliyuncs.com/' && is_string($request['Signature']));
            $action = $request['Action'];
            if ($action === 'DescribeSubDomainRecords') {
                $rows = array_values(array_filter($fixture['rows'], fn ($r) => $r['RR'] . '.dnscloudcloud.top' === $request['SubDomain']));
                return Http::response(['TotalCount' => count($rows), 'PageNumber' => 1, 'PageSize' => 50, 'DomainRecords' => ['Record' => $rows]]);
            }
            if ($action === 'DescribeDomainRecordInfo') return isset($fixture['rows'][$request['RecordId']])
                ? Http::response($fixture['rows'][$request['RecordId']]) : Http::response(['Code' => 'InvalidRR.NoExist'], 400);
            $fixture['writes'][] = $action;
            if ($action === 'AddDomainRecord') {
                $id = (string) (5001 + count($fixture['rows']));
                $fixture['rows'][$id] = ['RecordId' => $id, 'DomainName' => 'dnscloudcloud.top', 'RR' => $request['RR'],
                    'Type' => $request['Type'], 'Value' => $request['Value'], 'Line' => 'default', 'TTL' => 600,
                    'Remark' => '', 'Status' => 'ENABLE'];
            } else {
                $id = $request['RecordId']; $need(isset($fixture['rows'][$id]));
                if ($action === 'UpdateDomainRecordRemark') {
                    if ($mode === 'dns-edge-b-reject') {
                        $save('fixture', $fixture); return Http::response(['Code' => 'Forbidden.RAM'], 403);
                    }
                    $fixture['rows'][$id]['Remark'] = $request['Remark'];
                } elseif ($action === 'DeleteDomainRecord') unset($fixture['rows'][$id]);
                else throw new RuntimeException('fixture_dns_unexpected_operation');
            }
            $save('fixture', $fixture);
            if ($mode === 'dns-edge-a-lost' && $action === 'UpdateDomainRecordRemark')
                throw new ConnectionException('simulated-private-provider-detail');
            return Http::response(['RecordId' => $id, 'RequestId' => 'fixture-request']);
        });
        $records = [];
        foreach (['control' => '8.8.8.8', 'edge-a' => '1.1.1.1', 'edge-b' => '9.9.9.9'] as $role => $value)
            $records[$role] = ['value' => $value, 'marker' => 'keli-set-' . substr(hash('sha256', $role), 0, 48)];
        $setup = new MemberDnsInitialization(['run_id' => MemberProviderBindings::RUN,
            'credential_id' => $credential->id, 'credential_revision' => 1, 'records' => $records], $credential, $guard, $load, $save);
        $result = [];
        try {
            if ($mode === 'dns-prepare') $result = ['prepared' => true];
            elseif ($mode === 'dns-control') $result = $setup->advance('control');
            elseif (in_array($mode, ['dns-edge-a', 'dns-edge-a-lost'], true)) $result = $setup->advance('edge-a');
            elseif ($mode === 'dns-edge-a-read') $result = $setup->readback('edge-a');
            elseif ($mode === 'dns-edge-b-reject') $result = $setup->advance('edge-b');
            elseif ($mode === 'dns-stop') { $fixture['stopped'] = true; $save('fixture', $fixture); $result = ['stopped' => true]; }
            elseif ($mode === 'dns-cleanup') {
                $need($fixture['stopped']); $absent = 0;
                foreach (array_keys($records) as $role) {
                    $read = $setup->readback($role); $need($read['resolved'] && is_array($read['pin'])); $pin = $read['pin'];
                    $provider = new MemberProviderBindings(['run_id' => MemberProviderBindings::RUN,
                        'baseline_ids' => [], 'pins' => [MemberProviderBindings::digest($pin)]],
                        ['api_token' => 'fixture-unused'], $credential->credentials, $guard, $load, $save);
                    $provider->dispatch('dns-delete', $pin);
                    $absent += $provider->dispatch('dns-inspect', $pin)['status'] === 'absent' ? 1 : 0;
                }
                $result = ['absent' => $absent];
            } else throw new RuntimeException('fixture_mode_invalid');
        } catch (DnsException $error) {
            $result = ['provider_failure' => true, 'class' => (new ReflectionClass($error))->getShortName()];
        }
        foreach ($closed as $key => $value) $need(config($key) === $value);
        // Export counters only. Synthetic secrets, API payloads and record bodies stay local.
        $counter = Cache::get($cacheKey);
        $need((is_int($counter) || is_string($counter) && ctype_digit($counter)) && $counter >= 1 && $counter <= 100);
        return $result + ['encrypted_credential' => true, 'redis_counter' => (int) $counter,
            'redis_read_type' => get_debug_type($counter),
            'create_calls' => count(array_filter($fixture['writes'], fn ($a) => $a === 'AddDomainRecord')),
            'claim_calls' => count(array_filter($fixture['writes'], fn ($a) => $a === 'UpdateDomainRecordRemark')),
            'delete_calls' => count(array_filter($fixture['writes'], fn ($a) => $a === 'DeleteDomainRecord')),
            'closed_gates_unchanged' => true];
    } finally { flock($lock, LOCK_UN); fclose($lock); }
};
