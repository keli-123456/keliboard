<?php
namespace Tests\Unit\Services;

use Keli\Acceptance\MemberProviderBindings;
use Keli\Acceptance\MemberSourceConfiguration;
use PHPUnit\Framework\TestCase;

class MachineMemberSourceConfigurationTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/machine-acceptance/member_provider_bindings.php';
        require_once dirname(__DIR__, 3) . '/tools/machine-acceptance/member_source_configuration.php';
    }

    private function scope(): array
    {
        return ['run_id' => MemberProviderBindings::RUN, 'admin_id' => 1, 'account_id' => 1, 'account_revision' => 1,
            'group_id' => 1, 'dns_credential_id' => 1, 'dns_credential_revision' => 1, 'release_id' => 1,
            'binary_sha256' => str_repeat('a', 64), 'version' => 'v0.1.387', 'baseline_ids' => ['1001']];
    }

    public static function invalidScopes(): array
    {
        return [
            'wrong round' => [['run_id' => 'another-round']],
            'string id' => [['admin_id' => '1']],
            'zero revision' => [['account_revision' => 0]],
            'invalid binary pin' => [['binary_sha256' => 'unknown']],
            'invalid version' => [['version' => 'latest']],
            'repeated baseline' => [['baseline_ids' => ['1001', '1001']]],
            'numeric baseline' => [['baseline_ids' => [1001]]],
            'noncanonical baseline' => [['baseline_ids' => ['01001']]],
            'unknown capability' => [['allow_creation' => true]],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidScopes')]
    public function testScopeIsExactAndCannotGrantCapabilities(array $change): void
    {
        $unexpected = static fn () => throw new \LogicException('side_effect_before_validation');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('member_configuration_scope_invalid');
        new MemberSourceConfiguration(array_replace($this->scope(), $change), $unexpected, $unexpected, $unexpected);
    }

    public function testGuardRunsBeforeApplicationDatabaseOrJournal(): void
    {
        $operations = [];
        $guard = static function ($operation) use (&$operations): void {
            $operations[] = $operation; throw new \RuntimeException('stopped');
        };
        $unexpected = static fn () => throw new \LogicException('journal_access_after_stop');
        $setup = new MemberSourceConfiguration($this->scope(), $guard, $unexpected, $unexpected);
        foreach (['create', 'readback', 'authorizationBinding'] as $method) {
            try { $setup->$method(); $this->fail('guard was bypassed'); }
            catch (\RuntimeException $error) { $this->assertSame('stopped', $error->getMessage()); }
        }
        $this->assertSame(['source-configure', 'source-configuration-read', 'source-authorize'], $operations);
    }
}
