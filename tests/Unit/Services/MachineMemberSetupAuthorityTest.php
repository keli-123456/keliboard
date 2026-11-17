<?php
namespace Tests\Unit\Services;

use Keli\Acceptance\MemberSetupAuthority;
use PHPUnit\Framework\TestCase;

class MachineMemberSetupAuthorityTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/machine-acceptance/member_setup_authority.php';
    }

    public static function deniedWindows(): array
    {
        return [
            'zero start' => [0, 7200, 1, 'window_invalid'],
            'no execution window' => [1000, 2200, 1000, 'window_invalid'],
            'too long' => [1000, 8201, 1001, 'window_invalid'],
            'clock reversed' => [1000, 8200, 999, 'clock_invalid'],
            'cleanup reserve begins' => [1000, 8200, 7000, 'expired'],
            'expired' => [1000, 8200, 8201, 'expired'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('deniedWindows')]
    public function testDispatchWindowCannotUseCleanupReserve(int $start, int $deadline, int $now, string $reason): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('member_setup_' . $reason);
        MemberSetupAuthority::window($start, $deadline, $now, true);
    }

    public function testReadbackSurvivesExpiryButCannotExtendDispatch(): void
    {
        MemberSetupAuthority::window(1000, 8200, 6999, true);
        MemberSetupAuthority::window(1000, 8200, 9000, false);
        $this->addToAssertionCount(2);
    }
}
