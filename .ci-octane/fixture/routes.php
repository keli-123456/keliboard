<?php

declare(strict_types=1);

use App\Http\Controllers\V2\Admin\Server\MachineFailoverExampleController;
use App\Http\Routes\V2\AdminRoute;
use App\Models\User;
use App\Services\ServerMachine\ExampleRehearsal\WorkerSocket;
use Illuminate\Http\Request;

require_once __DIR__ . '/helpers.php';
$router = app('router');
$router->get('/_ci_octane_identity', static function (Request $request) {
    $parent = '/run/keli-failover-example';
    $file = $parent . '/worker.sock';
    $read = [];
    foreach (file('/proc/self/mountinfo', FILE_IGNORE_NEW_LINES) as $line) {
        $items = explode(' ', $line);
        if (in_array($items[4], [$parent, '/www/app', '/www/config', '/www/config/v2board.php', '/www/public/assets/admin-xboard', '/www/bootstrap/cache'], true)) {
            $read[$items[4]] = in_array('ro', explode(',', $items[5]), true) ? 'ro' : 'rw';
        }
    }
    $status = file_get_contents('/proc/self/status');
    preg_match('/^CapEff:\s+([0-9a-f]+)$/m', $status, $cap);
    $acl = null;
    if ($request->query('local_test') === '1') {
        $conn = @stream_socket_client('unix://' . $file, $errno, $errstr, 0.5);
        $acl = is_resource($conn) ? 'wrong_reply' : 'denied';
        if (is_resource($conn)) {
            stream_set_timeout($conn, 1);
            fwrite($conn, "octane-acl-only\n");
            if (fgets($conn, 128) === "fixture-ok\n") $acl = 'fixture_reply';
            fclose($conn);
        } elseif ($errno !== 13) {
            $acl = 'unexpected_errno';
        }
    }
    $reflection = new ReflectionClass(AdminRoute::class);
    $candidateLoaded = class_exists(MachineFailoverExampleController::class, false)
        && (new ReflectionClass(MachineFailoverExampleController::class))->getFileName() ===
            '/www/app/Http/Controllers/V2/Admin/Server/MachineFailoverExampleController.php';
    return response()->json(['pid' => getmypid(), 'uid' => posix_geteuid(), 'gid' => posix_getegid(), 'groups' => posix_getgroups(),
        'cap_eff' => $cap[1] ?? null, 'swoole' => phpversion('swoole'), 'php' => PHP_VERSION, 'octane' => (bool) env('LARAVEL_OCTANE'),
        'admin_loaded_from_release' => $reflection->getFileName() === '/www/app/Http/Routes/V2/AdminRoute.php',
        'candidate_controller_loaded' => $candidateLoaded,
        'socket_mode' => @filetype($file) === 'socket' ? fileperms($file) & 0777 : null, 'acl' => $acl,
        'mounts' => $read, 'files' => mountedFiles(), 'cache_anchor_present' => is_file('/www/bootstrap/cache/shared-anchor'),
        'config_cache_path' => app()->getCachedConfigPath(), 'loaded_from_cache' => app('config_loaded_from_cache'),
        'config_path' => app()->configPath(), 'original_config_loaded' => config('v2board.keli_ci_mount') === 'original-only',
        'example_config_closed' => config('failover_example') === array_fill_keys(['id','admin','opens','expires'], null),
        'grant_supplied' => (bool) getenv('FAILOVER_EXAMPLE_GRANT_ID')]);
});

// Only exercise the real controller's no-grant GET. No mutation routes are registered.
if (class_exists(MachineFailoverExampleController::class)) {
    $router->get('/api/v2/fixture-admin/server/machine/cloud/failover/example', static function (Request $request) {
        $request->setUserResolver(static fn () => (new User())->forceFill(['id' => 42, 'is_admin' => true, 'banned' => false]));
        return app(MachineFailoverExampleController::class)->status($request, app(WorkerSocket::class));
    });
}
