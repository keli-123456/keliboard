<?php

declare(strict_types=1);

use App\Models\{MachineFailoverAttempt as Attempt, MachineFailoverExecution as Execution, MachineFailoverExecutionMember as Member,
    MachineFailoverHandoverGrant as Grant, MachineFailoverRuntime as Runtime, MachineProvisionTask as Task};
use App\Services\ServerMachine\Cloud\{MachineFailoverHandover, MachineFailoverNativeBridge, MachineFailoverServiceRegistry,
    MachineFailoverWorkflow as Workflow, MachineUsdo1HandoverScope as Scope, ProvisionPlan};
use Illuminate\Cache\CacheServiceProvider;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\EncryptionServiceProvider;
use Illuminate\Foundation\Bootstrap\{LoadConfiguration, LoadEnvironmentVariables};
use Illuminate\Support\Facades\{DB, Facade};
use Illuminate\Support\Str;

$root = __DIR__.'/release';
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->bootstrapWith([LoadEnvironmentVariables::class, LoadConfiguration::class]);
Facade::clearResolvedInstances();
Facade::setFacadeApplication($app);
foreach ([DatabaseServiceProvider::class, CacheServiceProvider::class, EncryptionServiceProvider::class] as $provider) {
    $app->register($provider);
}
Model::setConnectionResolver($app['db']);
Model::unsetEventDispatcher();

$mode = $argv[1] ?? '';
if ($mode === 'revoke') {
    $owner = DB::table('receipt_fixture_owner')->value('owner');
    if ($owner !== getenv('KELI_RECEIPT_FIXTURE_OWNER') || Grant::query()->count() !== 1) exit(1);
    $grant = Grant::query()->sole();
    app(MachineFailoverHandover::class)->revoke($grant->id, $grant->admin_id);
    $row = $grant->fresh();
    echo json_encode(['revoked'=>is_int($row->revoked_at) && $row->revoked_by === $row->admin_id], JSON_THROW_ON_ERROR);
    exit;
}
if ($mode !== 'seed' || filesize(__DIR__.'/rehearsal.sqlite') !== 0) exit(2);
$migrations = [
    '2026_06_12_000002_create_v2_server_machine_release_table',
    '2026_07_14_000001_add_binary_sha256_to_server_machine_release',
    '2026_11_05_160000_create_machine_dns_tables', '2026_11_05_170000_create_machine_cloud_tables',
    '2026_11_05_220000_create_machine_provision_tasks', '2026_11_06_010000_add_machine_cloud_providers',
    '2026_11_06_030000_create_machine_failover_policies', '2026_11_06_050000_create_machine_acceptance_tables',
    '2026_11_07_140000_create_machine_failover_services', '2026_11_07_180000_create_machine_failover_native_journal',
    '2026_11_07_220000_create_machine_failover_runtime', '2026_11_09_180000_create_machine_failover_dns_sets',
    '2026_11_18_000000_create_machine_failover_handover_grants', '2026_11_18_010000_add_machine_failover_handover_receipts',
];
foreach ($migrations as $name) {
    (require $root.'/database/migrations/'.$name.'.php')->up();
}
DB::statement('CREATE TABLE receipt_fixture_owner (owner TEXT NOT NULL)');
DB::statement('CREATE TABLE v2_user (id INTEGER PRIMARY KEY, is_admin INTEGER NOT NULL, banned INTEGER NOT NULL)');
DB::statement('CREATE TABLE v2_server_machine (id INTEGER PRIMARY KEY, is_active INTEGER NOT NULL, token TEXT NOT NULL)');
DB::statement('CREATE TABLE receipt_fixture_writes (kind TEXT NOT NULL)');

$admin = 701;
$account = 702;
$policy = 703;
$member = 704;
$releaseId = 705;
$now = time();
$stamp = ['created_at'=>$now,'updated_at'=>$now];
$origin = 'https://receipt.example.test';
$grant = (string) Str::uuid();
$run = (string) Str::uuid();
$attempt = (string) Str::uuid();
$task = (string) Str::uuid();
$installation = bin2hex(random_bytes(16));
$sourceInstall = bin2hex(random_bytes(16));
$sourceToken = bin2hex(random_bytes(24));
$secret = bin2hex(random_bytes(32));
$authority = hash('sha256',$sourceToken);
$release = ['id'=>$releaseId,'component'=>'kelinode-rs','platform'=>'linux-x86_64','version'=>'fixture-only',
    'sha256'=>str_repeat('a',64),'binary_sha256'=>str_repeat('b',64)];
$baseline = hash('sha256','sqlite-fixture-baseline');
$created = '2026-01-01T00:00:00Z';
$sourceIP = '8.8.4.4'; // Stored as fixture data only. No network operation uses it.
$plan = ProvisionPlan::normalize(['account_id'=>$account,'machine_id'=>Scope::MACHINE,'slot'=>'receipt-fixture',
    'panel_origin'=>'https://panel.example.test','region'=>'ap-south','size'=>'g6-standard-4','image'=>'linode/debian12',
    'authorized_keys'=>[],'monthly_limit_usd'=>48]);
DB::table('receipt_fixture_owner')->insert(['owner'=>getenv('KELI_RECEIPT_FIXTURE_OWNER')]);
DB::table('v2_user')->insert(['id'=>$admin,'is_admin'=>1,'banned'=>0]);
DB::table('v2_server_machine')->insert(['id'=>Scope::MACHINE,'is_active'=>1,'token'=>$sourceToken]);
DB::table('v2_server_machine_release')->insert($release + ['manifest_path'=>'fixture','archive_path'=>'fixture'] + $stamp);
DB::table('v2_machine_cloud_account')->insert(['id'=>$account,'name'=>'sqlite fixture','provider'=>'linode',
    'credentials'=>'not-a-provider-credential'] + $stamp);
DB::table('v2_machine_cloud_resource')->insert(['account_id'=>$account,'external_id'=>'72001','label'=>'fixture',
    'region'=>$plan['region'],'size'=>$plan['size'],'provider_created_at'=>$created,'status'=>'running',
    'public_ips'=>json_encode([$sourceIP],JSON_THROW_ON_ERROR),'observed_at'=>$now,'machine_id'=>Scope::MACHINE,
    'installation_id'=>$sourceInstall,'machine_authority'=>$authority] + $stamp);
DB::table('v2_machine_failover_service')->insert(['id'=>1,'name'=>'123','attempt_limit'=>1,'updated_by'=>$admin] + $stamp);
DB::table('v2_machine_failover_policy')->insert(['id'=>$policy,'account_id'=>$account,'account_revision'=>1,
    'machine_authority'=>$authority,'name'=>'fixture','machine_id'=>Scope::MACHINE,'slot'=>$plan['slot'],'size'=>$plan['size'],
    'region'=>$plan['region'],'image'=>$plan['image'],'monthly_limit_usd'=>48,'failure_threshold'=>3,'daily_limit'=>1,
    'cooldown_seconds'=>300,'stale_after_seconds'=>300,'isolation_confirmed'=>1,'updated_by'=>$admin] + $stamp);
DB::table('v2_machine_failover_member')->insert(['id'=>$member,'name'=>'fixture','policy_id'=>$policy,'policy_revision'=>1,
    'machine_id'=>Scope::MACHINE,'service_id'=>1,'priority'=>1] + $stamp);
DB::table('v2_machine_failover_dns_set')->insert(['id'=>706,'service_id'=>1,'credential_id'=>707,'credential_revision'=>1,
    'provider'=>'aliyun','zone'=>Scope::ZONE,'zone_name'=>Scope::ZONE,'hostname'=>Scope::NAME,'scope'=>hash('sha256','fixture-set'),
    'ttl'=>Scope::TTL,'updated_by'=>$admin] + $stamp);
DB::table('v2_machine_failover_dns_slot')->insert(['set_id'=>706,'member_id'=>$member,'type'=>'A','line'=>'default',
    'marker'=>'sqlite-fixture-only'] + $stamp);
$registry = app(MachineFailoverServiceRegistry::class);
$snapshot = $registry->snapshot(1,1);
$context = $registry->fingerprint($snapshot);
$manual = ['source'=>['machine_id'=>Scope::MACHINE,'installation_id'=>$sourceInstall,
    'external_id'=>'72001','created_at'=>$created,'ipv4'=>$sourceIP],'record_id'=>'72002','baseline_sha256'=>$baseline];
Scope::validate($manual);
Scope::assertCurrent($manual,$snapshot);
$requestKey = substr(hash('sha256','usdo1-timed-execution-v1:'.$grant.':start'),0,32);
$deadline = $now+3600;
Grant::query()->create(['id'=>$grant,'execution_id'=>$run,'service_id'=>1,'service_revision'=>1,
    'admin_id'=>$admin,'context_hash'=>$context,'release'=>$release,'evidence_sha256'=>hash('sha256','fixture-evidence'),
    'writer_slot'=>1,'issued_at'=>$now-1,'expires_at'=>$now+3700,
    'stop_receipt'=>['proof_sha256'=>str_repeat('c',64),'fence_sha256'=>str_repeat('d',64),
                     'baseline_sha256'=>$baseline,'manual_usdo1'=>$manual]]);
Execution::query()->create(['id'=>$run,'service_id'=>1,'service_revision'=>1,'admin_id'=>$admin,'deadline_at'=>$deadline,
    'context_hash'=>$context,'intent_hash'=>hash('sha256','fixture-intent'),
    'request_key'=>hash('sha256',json_encode([$admin,$requestKey],JSON_THROW_ON_ERROR)),
    'payload'=>['handover_grant_id'=>$grant,'snapshot'=>$snapshot,'release'=>$release,'workflow'=>Workflow::READINESS]]);
$row = Member::query()->create(['execution_id'=>$run,'member_id'=>$member,'policy_id'=>$policy,'policy_revision'=>1,
    'plan_id'=>708,'episode_key'=>hash('sha256','fixture-episode'),'phase'=>'ready','attempt_id'=>$attempt,'payload'=>[]]);
Runtime::query()->create(['execution_id'=>$run,'next_check_at'=>$now+600]);
$taskRow = Task::query()->create(['id'=>$task,'status'=>'waiting_provider','admin_id'=>$admin,'account_id'=>$account,
    'account_revision'=>1,'machine_id'=>Scope::MACHINE,'machine_authority'=>$authority,'slot'=>$plan['slot'],
    'installation_id'=>$installation,'callback_hash'=>hash('sha256',$secret),'request_key'=>hash('sha256','fixture-request'),
    'plan_hash'=>hash('sha256','fixture-plan'),'marker'=>hash('sha256','fixture-marker'),'dispatched_at'=>$now-1,
    'deadline_at'=>$deadline,'next_check_at'=>$now+600,
    'payload'=>['plan'=>$plan,'request'=>['synthetic'=>true],'coordinated_attempt'=>$attempt,'usdo1_callback_origin'=>$origin]]);
$identity = (new ReflectionMethod(MachineFailoverNativeBridge::class,'taskIdentity'))->invoke(
    app(MachineFailoverNativeBridge::class),$taskRow);
Attempt::query()->create(['id'=>$attempt,'execution_member_id'=>$row->id,'ordinal'=>1,'task_id'=>$task,
    'request_id'=>bin2hex(random_bytes(16)),'account_id'=>$account,'account_revision'=>1,
    'reserved_cents'=>0,'status'=>'bound','payload'=>['plan'=>$plan,'task_identity'=>$identity]]);
foreach ([
    'task_update'=>['UPDATE','v2_machine_provision_task'],
    'receipt_insert'=>['INSERT','v2_machine_provision_step'],
    'wake_update'=>['UPDATE','v2_machine_failover_runtime'],
] as $kind=>[$event,$table]) {
    DB::unprepared("CREATE TRIGGER fixture_$kind AFTER $event ON $table BEGIN INSERT INTO receipt_fixture_writes(kind) VALUES ('$kind'); END");
}
echo json_encode(['grant'=>$grant,'task'=>$task,'installation'=>$installation,'secret'=>$secret,'admin'=>$admin],JSON_THROW_ON_ERROR);
