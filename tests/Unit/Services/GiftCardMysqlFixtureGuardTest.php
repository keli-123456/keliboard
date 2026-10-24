<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GiftCardMysqlFixture;
use Tests\TestCase;

final class GiftCardMysqlFixtureGuardTest extends TestCase
{
    public function test_ci_fixture_requires_loopback_and_a_dedicated_database_account(): void
    {
        $configuration = GiftCardMysqlFixture::configuration(self::environment());
        $this->assertSame('127.0.0.1', $configuration['host']);
        $this->assertSame(GiftCardMysqlFixture::DATABASE, $configuration['database']);
        $this->assertSame(GiftCardMysqlFixture::USER, $configuration['username']);
        $this->assertSame('InnoDB', $configuration['engine']);
    }

    #[DataProvider('unsafeEnvironments')]
    public function test_unsafe_or_implicit_database_targets_are_rejected(array $changes): void
    {
        $this->expectException(\RuntimeException::class);
        GiftCardMysqlFixture::configuration(array_replace(self::environment(), $changes));
    }

    public static function unsafeEnvironments(): array
    {
        return [[['GITHUB_ACTIONS' => 'false']], [['KELI_GIFTCARD_MYSQL_ACCEPTANCE' => '0']],
            [['KELI_GIFTCARD_DB_HOST' => '203.0.113.10']], [['KELI_GIFTCARD_DB_HOST' => 'example.test']],
            [['KELI_GIFTCARD_DB_NAME' => 'production']], [['KELI_GIFTCARD_DB_USER' => 'root']],
            [['KELI_GIFTCARD_DB_PASSWORD' => '']], [['KELI_GIFTCARD_DB_PORT' => '3306;bad']],
            [['KELI_GIFTCARD_DB_PORT' => 80]], [['KELI_GIFTCARD_DB_PORT' => 65536]],
            [['KELI_GIFTCARD_ISOLATION' => 'READ UNCOMMITTED']]];
    }

    private static function environment(): array
    {
        return ['GITHUB_ACTIONS' => 'true', 'KELI_GIFTCARD_MYSQL_ACCEPTANCE' => '1',
            'KELI_GIFTCARD_DB_HOST' => '127.0.0.1', 'KELI_GIFTCARD_DB_PORT' => '3306',
            'KELI_GIFTCARD_DB_NAME' => GiftCardMysqlFixture::DATABASE, 'KELI_GIFTCARD_DB_USER' => GiftCardMysqlFixture::USER,
            'KELI_GIFTCARD_DB_PASSWORD' => 'ephemeral-test-only', 'KELI_GIFTCARD_ISOLATION' => 'REPEATABLE READ'];
    }
}
