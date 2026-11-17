<?php
declare(strict_types=1);

// Dedicated isolated callback/report front controller; never a production route patch.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    if (PHP_OS_FAMILY !== 'Linux' || !in_array(PHP_SAPI, ['fpm-fcgi', 'cli-server'], true)
        || !function_exists('posix_geteuid') || posix_geteuid() === 0
        || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192)
        throw new RuntimeException('callback_denied');
    require_once __DIR__ . '/member_cli_authority.php';
    require_once __DIR__ . '/member_application_bootstrap.php';
    require_once __DIR__ . '/member_provider_bindings.php';
    require_once __DIR__ . '/member_source_configuration.php';
    require_once __DIR__ . '/member_setup_authority.php';
    require_once __DIR__ . '/member_candidate_callbacks.php';
    require_once __DIR__ . '/member_callback_request.php';
    $app = Keli\Acceptance\MemberApplicationBootstrap::boot();
    $request = Illuminate\Http\Request::capture();
    $origin = parse_url(config('app.url'), PHP_URL_HOST);
    $tls = ($_SERVER['HTTPS'] ?? '') === 'on' || (in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (!$tls || $request->getHost() !== $origin || strlen($request->getContent()) > 8192)
        throw new RuntimeException('callback_denied');
    $identity = Keli\Acceptance\MemberCallbackRequest::identify($request);
    $authority = Keli\Acceptance\MemberSetupAuthority::open();
    $handle = static function () use ($app, $request) {
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        return $response;
    };
    if ($identity['kind'] === 'callback') {
        $response = $authority->callback($identity['task_id'], $identity['installation_id'], $handle);
    } else {
        $response = $authority->report($identity['machine_id'], $identity['installation_id'], $identity['node_id'], $handle);
    }
    $response->send();
} catch (Throwable) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo '{"message":"isolated_callback_denied"}';
}
