<?php
declare(strict_types=1);

// Dedicated isolated callback front controller; never a production route patch.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    if (PHP_OS_FAMILY !== 'Linux' || !in_array(PHP_SAPI, ['fpm-fcgi', 'cli-server'], true)
        || !function_exists('posix_geteuid') || posix_geteuid() === 0
        || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || ($_SERVER['REQUEST_URI'] ?? '') !== '/api/v2/server/machine/provision/result'
        || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 2048)
        throw new RuntimeException('callback_denied');
    require_once __DIR__ . '/member_cli_authority.php';
    require_once __DIR__ . '/member_application_bootstrap.php';
    require_once __DIR__ . '/member_provider_bindings.php';
    require_once __DIR__ . '/member_source_configuration.php';
    require_once __DIR__ . '/member_setup_authority.php';
    $app = Keli\Acceptance\MemberApplicationBootstrap::boot();
    $request = Illuminate\Http\Request::capture();
    $origin = parse_url(config('app.url'), PHP_URL_HOST);
    $tls = ($_SERVER['HTTPS'] ?? '') === 'on' || (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (!$tls || $request->getHost() !== $origin || strlen($request->getContent()) > 2048)
        throw new RuntimeException('callback_denied');
    $input = $request->json()->all();
    if (!is_string($input['task_id'] ?? null) || !is_string($input['installation_id'] ?? null))
        throw new RuntimeException('callback_denied');
    $authority = Keli\Acceptance\MemberSetupAuthority::open();
    $response = $authority->callback($input['task_id'], $input['installation_id'], static function () use ($app, $request) {
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        return $response;
    });
    $response->send();
} catch (Throwable) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo '{"message":"isolated_callback_denied"}';
}
