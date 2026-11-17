<?php
declare(strict_types=1);

// Fixed installer/readback CLI. No web route, implicit migration or settings editor.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || realpath(__DIR__) !== '/opt/keli-isolated/runtime')
        throw new RuntimeException('application_install_cli_required');
    foreach (['member_cli_authority.php', 'member_application_bootstrap.php', 'member_bootstrap_controller.php'] as $name) {
        $path = __DIR__ . '/' . $name; $stat = @lstat($path);
        if (!$stat || realpath($path) !== $path || !is_file($path) || $stat['uid'] !== 0
            || $stat['nlink'] !== 1 || ($stat['mode'] & 0022)) throw new RuntimeException('application_install_runtime_changed');
        require_once $path;
    }
    $raw = stream_get_contents(STDIN, 1025);
    if (strlen($raw) > 1024) throw new RuntimeException('application_install_request_invalid');
    $request = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($request)) throw new RuntimeException('application_install_request_invalid');
    echo json_encode(Keli\Acceptance\MemberApplicationBootstrap::install($request), JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $error) {
    echo json_encode(['ok' => false, 'reason' => 'isolated_application_install_rejected',
        'diagnostic' => ['class' => (new ReflectionClass($error))->getShortName(),
            'file' => basename($error->getFile()), 'line' => $error->getLine()]]), "\n";
    exit(1);
}
