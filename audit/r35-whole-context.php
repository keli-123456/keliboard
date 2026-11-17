<?php
declare(strict_types=1);

// Transfer only the simulated provider's existing state, never stage receipts.
try {
if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('KELI_MEMBER_FULL_APPLICATION') !== '1'
    || PHP_OS_FAMILY !== 'Linux' || posix_geteuid() !== 0 || count($argv) !== 2) exit(1);
$runtime = '/opt/keli-isolated/runtime/';
foreach (['member_cli_authority', 'member_application_bootstrap', 'member_provider_bindings',
    'member_prerequisite_import', 'member_source_configuration', 'member_setup_authority',
    'member_bootstrap_controller'] as $file) require_once $runtime . $file . '.php';
Keli\Acceptance\MemberApplicationBootstrap::boot();
$directory = '/opt/keli-isolated/private/setup-fixture';
if (file_exists($directory)) throw new RuntimeException('fixture_existing_context');
$cloud = json_decode(file_get_contents($argv[1] . '/fixture-cloud.json'), true, 32, JSON_THROW_ON_ERROR);
$dns = json_decode(file_get_contents($argv[1] . '/fixture-dns.json'), true, 32, JSON_THROW_ON_ERROR);
$bootstrap = new Keli\Acceptance\MemberBootstrapController();
try {
    $configuration = $bootstrap->rpc(['operation' => 'configuration-read'])['configuration'];
    $identity = Keli\Acceptance\MemberSetupAuthority::open()->identity();
    $grant = json_decode(file_get_contents('/opt/keli-isolated/member-setup-grant.json'), true, 32, JSON_THROW_ON_ERROR);
    if (!$cloud['present'] || count($dns['rows']) !== 1 || $dns['create'] !== 1 || $dns['claim'] !== 1)
        throw new RuntimeException('fixture_prefix_provider_state');
    if ((string) $cloud['row']['id'] !== $grant['control']['external_id']
        || $cloud['row']['label'] !== $grant['control']['label']
        || $cloud['row']['tags'] !== [$grant['control']['marker']]
        || $grant['binding']['configuration'] !== $configuration)
        throw new RuntimeException('fixture_prefix_identity_changed');
    if (App\Models\MachineProvisionTask::count() !== 0) throw new RuntimeException('fixture_preexisting_tasks');
    mkdir($directory, 0700);
    foreach (['identity' => $identity, 'initialization' => $configuration['initialization_scope'],
        'control-row' => $cloud['row'], 'setup-dns-provider' => $dns + ['next_id' => 6002]] as $name => $value) {
        $file = $directory . '/' . $name . '.json';
        $stream = fopen($file, 'xb'); chmod($file, 0600);
        $raw = json_encode($value, JSON_THROW_ON_ERROR);
        if (fwrite($stream, $raw) !== strlen($raw) || !fflush($stream) || !fsync($stream))
            throw new RuntimeException('fixture_context_write');
        fclose($stream);
    }
    echo json_encode(['bound' => true, 'application_tasks' => 0, 'provider_records' => 1]);
} finally { $bootstrap->close(); }
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'diagnostic' => [
        'class' => (new ReflectionClass($error))->getShortName(),
        'file' => basename($error->getFile()), 'line' => $error->getLine(),
        'reason' => preg_match('/^(fixture|member)_[a-z_]{1,90}$/D', $error->getMessage())
            ? $error->getMessage() : 'unclassified']]);
    exit(1);
}
