<?php
namespace Tests\Unit\Services;

use Keli\Acceptance\MemberApplicationBootstrap;
use Keli\Acceptance\MemberCliAuthority;
use PHPUnit\Framework\TestCase;

class MachineMemberApplicationInstallTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/machine-acceptance/member_cli_authority.php';
        require_once dirname(__DIR__, 3) . '/tools/machine-acceptance/member_application_bootstrap.php';
    }

    private function request(): array
    {
        return ['schema' => 1, 'run_id' => MemberCliAuthority::RUN, 'bundle_sha256' => str_repeat('a', 64),
            'started_at' => 1000, 'deadline_at' => 8200, 'request_id' => str_repeat('b', 32), 'operation' => 'initialize'];
    }

    public function testInitializeCannotUseCleanupReserveButReadbackCanRunAfterExpiry(): void
    {
        MemberApplicationBootstrap::validateInstallRequest($this->request(), 6999);
        foreach (['readback', 'service-smoke'] as $operation)
            MemberApplicationBootstrap::validateInstallRequest(array_replace($this->request(), ['operation' => $operation]), 9000);
        $this->expectExceptionMessage('member_application_install_window_expired');
        MemberApplicationBootstrap::validateInstallRequest($this->request(), 7000);
    }

    public function testMalformedRequestOrScopeCannotReachApplicationBootstrap(): void
    {
        foreach ([['schema' => true], ['operation' => 'migrate:fresh'], ['run_id' => 'other'],
            ['bundle_sha256' => str_repeat('a', 63)], ['request_id' => '../override'], ['started_at' => 0],
            ['started_at' => '1000'], ['deadline_at' => 8201], ['deadline_at' => 2200], ['host' => 'production']] as $change) {
            try {
                MemberApplicationBootstrap::validateInstallRequest(array_replace($this->request(), $change), 1100);
                $this->fail('Unbound installer request accepted');
            } catch (\RuntimeException $error) {
                $this->assertSame('member_application_install_request_invalid', $error->getMessage());
            }
        }
    }

    public function testReadbackCannotHideAClockRollback(): void
    {
        $this->expectExceptionMessage('member_application_install_clock_invalid');
        MemberApplicationBootstrap::validateInstallRequest(array_replace($this->request(), ['operation' => 'readback']), 999);
    }
}
