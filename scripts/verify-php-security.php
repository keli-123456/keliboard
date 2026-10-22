<?php

declare(strict_types=1);

/**
 * Minimum upstream fixes for CVE-2026-109618 and CVE-2026-109619.
 * https://www.php.net/archive/2026.php
 */
function phpSecurityBaselineError(string $version): ?string
{
    if (preg_match('/^(\d+\.\d+)\.\d+$/D', $version, $matches) !== 1) {
        return 'A recognized stable PHP release is required';
    }

    $minimums = [
        '8.2' => '8.2.35',
        '8.3' => '8.3.36',
        '8.4' => '8.4.27',
        '8.5' => '8.5.12',
    ];
    $minimum = $minimums[$matches[1]] ?? null;
    if ($minimum === null) {
        return 'PHP branch has no verified security baseline';
    }
    if (version_compare($version, $minimum, '<')) {
        return "PHP {$minimum} or newer in the same branch is required";
    }

    return null;
}

function verifyPhpSecurityRuntime(bool $container): array
{
    $errors = [];
    if (($error = phpSecurityBaselineError(PHP_VERSION)) !== null) {
        $errors[] = $error;
    }

    if ($container) {
        if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 2) {
            $errors[] = 'The application container must stay on the tested PHP 8.2 branch';
        }
        foreach (['bcmath', 'curl', 'gd', 'mbstring', 'openssl', 'pcntl', 'pdo_mysql', 'pdo_sqlite', 'redis', 'sodium', 'swoole', 'zip'] as $extension) {
            if (!extension_loaded($extension)) {
                $errors[] = "Missing required extension: {$extension}";
            }
        }
        foreach (['Swoole\\Http\\Server', 'Swoole\\WebSocket\\Server', 'Redis', 'PDO'] as $class) {
            if (!class_exists($class, false)) {
                $errors[] = "Missing runtime class: {$class}";
            }
        }
        if ($errors === []) {
            try {
                $database = new PDO('sqlite::memory:');
                if ((int) $database->query('SELECT 1')->fetchColumn() !== 1) {
                    $errors[] = 'SQLite runtime check failed';
                }
                $key = random_bytes(32);
                $iv = random_bytes(12);
                $tag = '';
                $encrypted = openssl_encrypt('runtime-check', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
                if ($encrypted === false || openssl_decrypt($encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag) !== 'runtime-check') {
                    $errors[] = 'OpenSSL runtime check failed';
                }
            } catch (Throwable $exception) {
                $errors[] = 'Container runtime check failed: ' . get_class($exception);
            }
        }
    }

    return [
        'status' => $errors === [] ? 'pass' : 'fail',
        'php_version' => PHP_VERSION,
        'swoole_version' => phpversion('swoole') ?: null,
        'container_checks' => $container,
        'errors' => $errors,
    ];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $arguments = array_slice($argv, 1);
    if ($arguments !== [] && $arguments !== ['--container']) {
        fwrite(STDERR, "Usage: php scripts/verify-php-security.php [--container]\n");
        exit(1);
    }
    $result = verifyPhpSecurityRuntime($arguments === ['--container']);
    fwrite($result['status'] === 'pass' ? STDOUT : STDERR, json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit($result['status'] === 'pass' ? 0 : 1);
}
