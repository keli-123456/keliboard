<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\ExampleRehearsal;

use App\Models\MachineCloudAccount;
use App\Models\MachineDnsCredential;
use App\Models\MachineFailoverDnsSlot;
use App\Models\MachineFailoverMember;
use App\Models\MachineFailoverPolicy;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerMachine\Cloud\MachineFailoverDnsRegistry;
use App\Services\ServerMachine\Cloud\MachineFailoverDnsSimulation;
use App\Services\ServerMachine\Cloud\MachineFailoverPolicyService;
use App\Services\ServerMachine\Cloud\MachineFailoverServiceRegistry;
use App\Services\ServerMachine\Cloud\MachineFailoverSimulation;
use App\Services\ServerMachine\Cloud\MachineFailoverWorkflow;
use App\Services\ServerMachine\Dns\MachineDnsRecordSetSimulationTransport;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class Fixture
{
    private const ZONE = 'failover-lab.test';
    private const ADMIN = 1;
    private int $blockedOperations = 0;

    public function __construct(private string $root, string $database, Grant $grant)
    {
        // Never boot the panel or load its .env, database, providers, Redis or queue config.
        $app = new Application($root);
        Container::setInstance($app);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
        $app->instance('config', new \Illuminate\Config\Repository(['machine_failover' => ['workflow' => MachineFailoverWorkflow::READINESS]]));
        $app->instance('encrypter', new Encrypter(hash('sha256', 'synthetic:' . $grant->fingerprint(), true), 'AES-256-CBC'));
        $app->instance('cache', new class(new ArrayStore()) extends Repository {
            public function store(?string $name = null): self { return $this; }
        });
        $reject = function (): never {
            $this->blockedOperations++;
            throw new RuntimeException('fixture_external_operation');
        };
        foreach (['queue', 'redis', \Illuminate\Contracts\Bus\Dispatcher::class, \Illuminate\Contracts\Bus\QueueingDispatcher::class] as $binding)
            $app->bind($binding, $reject);
        Http::swap(new Factory());
        Http::preventStrayRequests();
        Http::fake($reject);
        $capsule = new Manager($app);
        $capsule->addConnection(['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true,
            'journal_mode' => 'delete', 'synchronous' => 'full']);
        $capsule->bootEloquent();
        $app->instance('db', $capsule->getDatabaseManager());
        $app->instance('db.connection', $capsule->getConnection());
        $app->instance('db.schema', $capsule->getConnection()->getSchemaBuilder());
        $actual = DB::selectOne('PRAGMA database_list');
        $this->require($actual->name === 'main' && realpath($actual->file) === realpath($database));
    }

    public function seed(Grant $grant, callable $guard): void
    {
        foreach ([
            '2026_11_05_160000_create_machine_dns_tables', '2026_11_05_170000_create_machine_cloud_tables',
            '2026_11_05_200000_create_machine_operation_notices', '2026_11_05_220000_create_machine_provision_tasks',
            '2026_11_06_010000_add_machine_cloud_providers', '2026_11_06_020000_create_machine_cloud_groups',
            '2026_11_06_030000_create_machine_failover_policies', '2026_11_06_050000_create_machine_acceptance_tables',
            '2026_11_07_090000_create_machine_replacement_tasks', '2026_11_07_120000_add_provider_groups_to_machine_failover',
            '2026_11_07_140000_create_machine_failover_services', '2026_11_07_180000_create_machine_failover_native_journal',
            '2026_11_09_180000_create_machine_failover_dns_sets', '2026_11_09_200000_create_machine_dns_set_runtime',
            '2026_11_16_120000_add_machine_provision_limits',
        ] as $name) {
            $guard();
            (require $this->root . '/database/migrations/' . $name . '.php')->up();
        }
        Schema::create('v2_user', function (Blueprint $table) {
            $table->id(); $table->boolean('is_admin'); $table->boolean('banned')->default(false);
            $table->unsignedInteger('created_at'); $table->unsignedInteger('updated_at');
        });
        Schema::create('v2_server_machine', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('token'); $table->boolean('is_active'); $table->timestamps();
        });
        Schema::create('v2_server', function (Blueprint $table) {
            $table->id(); $table->unsignedInteger('machine_id'); $table->boolean('enabled');
        });
        Schema::create('example_round', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->string('fingerprint');
            foreach (['service_id', 'member_a', 'member_b', 'credential_id'] as $column) $table->unsignedInteger($column);
            $table->string('control_hash');
            $table->unsignedInteger('v2_finished_at')->nullable();
            $table->unsignedInteger('dns_finished_at')->nullable();
        });
        $this->require(User::query()->create(['is_admin' => true])->id === self::ADMIN);
        $account = MachineCloudAccount::query()->create(['name' => 'Synthetic Linode', 'provider' => 'linode', 'revision' => 1,
            'credentials' => ['api_token' => 'fixture-only-invalid']]);
        $dns = MachineDnsCredential::query()->create(['name' => 'Synthetic Aliyun', 'provider' => 'aliyun', 'revision' => 1,
            'credentials' => ['access_key_id' => 'fixture-only-invalid', 'access_key_secret' => 'fixture-only-invalid']]);
        $members = [];
        foreach (['A', 'B'] as $i => $name) {
            $guard();
            $machine = ServerMachine::query()->create(['name' => 'Synthetic ' . $name, 'token' => bin2hex(random_bytes(24)), 'is_active' => true]);
            DB::table('v2_server')->insert(['machine_id' => $machine->id, 'enabled' => true]);
            $policy = app(MachineFailoverPolicyService::class)->save([
                'name' => 'Synthetic ' . $name, 'machine_id' => $machine->id, 'account_id' => $account->id,
                'region' => 'sg-sin-2', 'size' => 'g6-standard-4', 'image' => 'linode/debian12', 'failure_threshold' => 3,
                'stale_after_seconds' => 300, 'cooldown_seconds' => 1800, 'daily_limit' => 1, 'monthly_limit_usd' => 0,
                'dns_credential_id' => $dns->id, 'dns_hostname' => strtolower($name) . '.' . self::ZONE, 'isolation_confirmed' => true,
            ], self::ADMIN);
            $members[] = ['policy_id' => $policy['id'], 'policy_revision' => $policy['revision'], 'name' => $name,
                'enabled' => true, 'priority' => $i + 1, 'attempt_limit' => 1];
        }
        $service = app(MachineFailoverServiceRegistry::class)->save(['name' => 'lab-failover-01', 'max_parallel' => 1,
            'attempt_limit' => 1, 'members' => $members], self::ADMIN);
        [$a, $b] = array_column($service['members'], 'id');
        DB::table('example_round')->insert(['id' => 1, 'fingerprint' => $grant->fingerprint(), 'service_id' => $service['id'],
            'member_a' => $a, 'member_b' => $b, 'credential_id' => $dns->id, 'control_hash' => $this->controlHash($b)]);
        $guard();
    }

    public function state(Grant $grant): object
    {
        $row = DB::table('example_round')->where('id', 1)->first();
        $this->require($row && hash_equals($grant->fingerprint(), $row->fingerprint));
        return $row;
    }

    public function v2(object $row, string $intent, callable $guard): void
    {
        $this->require(!DB::table('v2_machine_failover_dns_set')->exists());
        $sim = app(MachineFailoverSimulation::class);
        $run = $sim->start($row->service_id, 1, 'no_baseline', $row->member_a, $intent, self::ADMIN);
        for ($failures = 1; $failures <= 3; $failures++) {
            $guard();
            $run = $sim->advance($run['id'], self::ADMIN, 1);
            $a = collect($run['members'])->firstWhere('member_id', $row->member_a);
            $this->require($a && $a['failures'] === $failures && $run['actions'] === []);
        }
        for ($step = 0; $step < 40 && $run['status'] === 'running'; $step++) {
            $guard();
            $run = $sim->advance($run['id'], self::ADMIN, 1);
        }
        $b = collect($run['members'])->firstWhere('member_id', $row->member_b);
        $this->require($run['status'] === 'completed' && $run['workflow'] === MachineFailoverWorkflow::READINESS
            && $run['attempts'] === 1 && array_column($run['actions'], 'kind') === ['create', 'initialize', 'reports', 'ipv4', 'prepare_dns', 'switch_dns', 'verify_dns']
            && array_unique(array_column($run['actions'], 'member_id')) === [$row->member_a]
            && $b && $b['phase'] === 'healthy' && $b['failures'] === 0 && !$b['synthetic_resource_retained']);
        $this->assertIsolated($run, $row);
    }

    public function dns(object $row, string $intent, callable $guard): void
    {
        $view = app(MachineFailoverDnsRegistry::class)->save([
            'service_id' => $row->service_id, 'service_revision' => 1, 'credential_id' => $row->credential_id, 'credential_revision' => 1,
            'zone' => self::ZONE, 'zone_name' => self::ZONE, 'hostname' => 'edge.' . self::ZONE, 'ttl' => 600,
            'members' => array_map(fn ($id) => ['member_id' => $id, 'types' => ['A', 'AAAA'], 'lines' => ['default', 'telecom']], [$row->member_a, $row->member_b]),
        ], self::ADMIN);
        $set = $view['sets'][0];
        $sim = app(MachineFailoverDnsSimulation::class);
        $transport = app(MachineDnsRecordSetSimulationTransport::class);
        $run = $sim->start($set['id'], $set['revision'], $view['service_revision'], $row->member_a, 'unknown_applied', $intent, self::ADMIN);
        $before = $transport->metrics($run['id']);
        for ($step = 0; $step < 40 && $run['status'] === 'running'; $step++) {
            $guard();
            $run = $sim->advance($run['id'], self::ADMIN, 1);
        }
        $after = $transport->metrics($run['id']);
        $this->require($run['status'] === 'completed' && $run['provider'] === 'aliyun' && $run['target_member_id'] === $row->member_a
            && $run['cloud_writes'] === 0 && $run['dns_writes'] === 0 && !$run['propagation_verified'] && !$run['automatic_rotation']
            && $run['simulated_writes'] === 4 && $run['verified_actions'] === 4 && $run['readback_attempts'] >= 4
            && count(array_filter($run['events'], fn ($event) => ($event['outcome'] ?? null) === 'unknown')) === 1);
        $original = array_column($before['records'], null, 'marker'); $actual = array_column($after['records'], null, 'marker');
        $slots = MachineFailoverDnsSlot::query()->where('set_id', $set['id'])->get();
        $this->require(count($original) === 8 && count($actual) === 8 && count($slots) === 8);
        $aIds = []; $bCount = 0;
        foreach ($slots as $slot) {
            $old = $original[$slot->marker] ?? null; $new = $actual[$slot->marker] ?? null;
            $this->require($old && $new && $new['ttl'] === 600);
            ksort($old); ksort($new);
            if ($slot->member_id === $row->member_a) {
                $this->require($new['content'] !== $old['content'] && $new === array_replace($old, ['content' => $new['content']]));
                $aIds[] = $old['id'];
            } else {
                $this->require($slot->member_id === $row->member_b && $new === $old);
                $bCount++;
            }
        }
        $updated = [];
        foreach ($after['calls'] as $call) {
            if ($call['kind'] === 'inventory') continue;
            $this->require($call['kind'] === 'update'); $updated[] = $call['wire']['parameters']['RecordId'];
        }
        sort($updated); sort($aIds);
        $this->require(count($aIds) === 4 && $bCount === 4 && $updated === $aIds);
        $this->assertIsolated($run, $row);
    }

    private function controlHash(int $id): string
    {
        $member = MachineFailoverMember::query()->findOrFail($id);
        $policy = MachineFailoverPolicy::query()->findOrFail($member->policy_id);
        $machine = ServerMachine::query()->findOrFail($member->machine_id);
        return hash('sha256', json_encode([$member->getRawOriginal(), $policy->getRawOriginal(), $machine->getRawOriginal()], JSON_THROW_ON_ERROR));
    }

    private function assertIsolated(array $run, object $row): void
    {
        $this->require($run['mode'] === 'simulation' && $run['simulation_only'] && $run['evidence_origin'] === 'synthetic'
            && !$run['execution_enabled'] && !$run['dns_cutover'] && !$run['business_verified'] && $this->blockedOperations === 0
            && hash_equals($row->control_hash, $this->controlHash($row->member_b)));
        foreach (['cloud_resource', 'cloud_task', 'provision_task', 'replacement_task', 'owned_instance', 'owned_dns',
            'dns_binding', 'dns_transition', 'failover_execution', 'failover_attempt', 'operation_notice'] as $table)
            $this->require(!DB::table('v2_machine_' . $table)->exists());
    }

    private function require(bool $condition): void
    {
        if (!$condition) throw new RuntimeException('fixture_assertion_failed');
    }
}
