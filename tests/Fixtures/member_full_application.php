<?php
declare(strict_types=1);

// CI-only full-kernel fixture. It never loads the in-memory TestCase application.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    if (getenv('GITHUB_ACTIONS') !== 'true' || getenv('KELI_MEMBER_FULL_APPLICATION') !== '1'
        || PHP_OS_FAMILY !== 'Linux' || !in_array(PHP_SAPI, ['cli', 'cli-server'], true))
        throw new RuntimeException('fixture_only');
    require '/opt/keli-isolated/runtime/member_cli_authority.php';
    require '/opt/keli-isolated/runtime/member_application_bootstrap.php';
    $app = Keli\Acceptance\MemberApplicationBootstrap::boot();
    $mode = PHP_SAPI === 'cli-server' ? 'http' : ($argv[1] ?? 'inspect');
    if ($mode === 'migrate') {
        $initialized = Keli\Acceptance\MemberApplicationBootstrap::initialize($app);
        foreach (['a', 'b'] as $letter) {
            App\Models\MachineProvisionTask::query()->create([
                'id' => str_repeat($letter, 8) . '-' . str_repeat($letter, 4) . '-4' . str_repeat($letter, 3)
                    . '-8' . str_repeat($letter, 3) . '-' . str_repeat($letter, 12),
                'request_key' => str_repeat($letter, 64), 'admin_id' => 1, 'account_id' => 1,
                'account_revision' => 1, 'machine_id' => $letter === 'a' ? 1 : 2,
                'machine_authority' => str_repeat($letter, 64), 'plan_hash' => str_repeat($letter, 64),
                'slot' => 'fixture-' . $letter, 'installation_id' => str_repeat($letter, 32),
                'callback_hash' => hash('sha256', str_repeat('f', 64)), 'payload' => [],
                'marker' => 'fixture-' . $letter, 'status' => 'bootstrapping',
                'dispatched_at' => time(), 'deadline_at' => time() + 600,
            ]);
        }
        echo json_encode($initialized);
    } elseif ($mode === 'initialize-readback') {
        echo json_encode(Keli\Acceptance\MemberApplicationBootstrap::initialized());
    } elseif ($mode === 'http') {
        if (posix_geteuid() === 0 || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
            || ($_SERVER['REQUEST_URI'] ?? '') !== '/api/v2/server/machine/provision/result'
            || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(404); echo '{}'; return;
        }
        Illuminate\Support\Facades\Http::preventStrayRequests();
        // Only the fixture callback request gets permission; queued work is discarded.
        // No worker, cloud service, execution gate or production setting is changed.
        config(['machine_provision.allow_live' => getenv('KELI_MEMBER_CALLBACK_DISABLED') !== '1']);
        $app->instance(Illuminate\Contracts\Bus\Dispatcher::class,
            new Illuminate\Bus\Dispatcher($app, static fn () => new Illuminate\Queue\NullQueue()));
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $request = Illuminate\Http\Request::capture();
        $response = $kernel->handle($request); $response->send(); $kernel->terminate($request, $response);
    } else {
        if ($mode !== 'inspect') throw new RuntimeException('fixture_mode_invalid');
        $identity = Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() AS db_name, CURRENT_USER() AS db_user');
        echo json_encode(['booted' => true, 'database' => $identity->db_name,
            'uid' => posix_geteuid(), 'kernel' => get_class($app->make(Illuminate\Contracts\Http\Kernel::class)),
            'states' => App\Models\MachineProvisionTask::query()->orderBy('machine_id')->pluck('bootstrap_state')->all(),
            'callback_steps' => Illuminate\Support\Facades\DB::table('v2_machine_provision_step')->count()]);
    }
} catch (Throwable $error) {
    http_response_code(500);
    $reason = preg_match('/^(member_application|fixture)_[a-z_]{1,70}$/D', $error->getMessage())
        ? $error->getMessage() : 'fixture_failed';
    echo json_encode(['ok' => false, 'reason' => $reason, 'class' => (new ReflectionClass($error))->getShortName(),
        'file' => basename($error->getFile()), 'line' => $error->getLine()]);
    exit(1);
}
