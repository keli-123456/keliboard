<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class RiskMigrationLock
{
    public static function run(callable $change): void
    {
        $connection = Schema::getConnection();
        if (!in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
            $change();
            return;
        }

        $pdo = $connection->getPdo();
        $previous = (int) $pdo->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn();
        if ($previous < 1) {
            throw new RuntimeException('Risk migration could not read the session lock timeout.');
        }
        if ($previous <= 3) {
            $change();
            return;
        }

        $pdo->exec('SET SESSION lock_wait_timeout = 3');
        try {
            $change();
        } finally {
            $pdo->exec('SET SESSION lock_wait_timeout = ' . $previous);
        }
    }
}
