<?php

declare(strict_types=1);

require __DIR__ . '/verify-php-security.php';

$cases = [
    '7.4.33' => false,
    '8.1.34' => false,
    '8.2.28' => false,
    '8.2.34' => false,
    '8.2.35' => true,
    '8.2.36' => true,
    '8.3.35' => false,
    '8.3.36' => true,
    '8.3.37' => true,
    '8.4.26' => false,
    '8.4.27' => true,
    '8.4.28' => true,
    '8.5.11' => false,
    '8.5.12' => true,
    '8.5.13' => true,
    '8.6.0' => false,
    '8.2.35RC1' => false,
    '8.2.35-dev' => false,
    '8.2' => false,
    '' => false,
];
foreach ($cases as $version => $expected) {
    if ((phpSecurityBaselineError($version) === null) !== $expected) {
        throw new RuntimeException("Unexpected security baseline decision for {$version}");
    }
}

fwrite(STDOUT, 'OK: PHP security baseline checks passed (' . count($cases) . " cases)\n");
