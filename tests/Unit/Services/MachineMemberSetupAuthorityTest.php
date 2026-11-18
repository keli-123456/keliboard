<?php
namespace Tests\Unit\Services;

use Keli\Acceptance\MemberSetupAuthority;
use PHPUnit\Framework\TestCase;

class MachineMemberSetupAuthorityTest extends TestCase
{
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 3) . '/tools/machine-acceptance/member_setup_authority.php';
        require_once dirname(__DIR__, 3) . '/tools/machine-acceptance/member_cli_authority.php';
        require_once dirname(__DIR__, 3) . '/tools/machine-acceptance/member_setup_controller.php';
        require_once dirname(__DIR__, 3) . '/tools/machine-acceptance/member_native_grant.php';
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

    public function testSetupRequestsHaveAnExactTypedGrantAndRunBinding(): void
    {
        $request = ['schema' => 1, 'run_id' => \Keli\Acceptance\MemberCliAuthority::RUN,
            'grant_id' => str_repeat('a', 32), 'grant_sha256' => str_repeat('b', 64),
            'deadline_at' => 9000, 'request_id' => str_repeat('c', 32), 'sequence' => 0, 'operation' => 'advance'];
        foreach (['advance', 'status', 'resources', 'evidence-export', 'stop', 'cleanup', 'dns-control', 'dns-edges', 'dns-readback', 'native-issue', 'native-read', 'native-export',
            'source-inspect-a', 'source-inspect-b', 'source-delete-a', 'source-delete-b'] as $operation) {
            \Keli\Acceptance\MemberSetupController::validateRequest(array_replace($request, ['operation' => $operation]));
            $this->addToAssertionCount(1);
        }
        foreach ([['schema' => true], ['run_id' => 'another'], ['grant_id' => '../invalid'],
            ['grant_sha256' => null], ['deadline_at' => '9000'], ['sequence' => -1], ['sequence' => false],
            ['request_id' => str_repeat('d', 33)], ['operation' => 'create'], ['operation' => 'source-delete-c'],
            ['operation' => 'source-delete-candidate-a1'], ['extra' => true]] as $change) {
            try {
                \Keli\Acceptance\MemberSetupController::validateRequest(array_replace($request, $change));
                $this->fail('Malformed setup request accepted');
            } catch (\RuntimeException $error) {
                $this->assertSame('member_setup_controller_request_invalid', $error->getMessage());
            }
        }
    }

    public function testNativeGrantDecisionRetainsThresholdIdentityCooldownAndOtherBlockers(): void
    {
        $source = ['policy_id' => 1, 'policy_revision' => 2, 'installation_id' => str_repeat('a', 32)];
        $summary = ['evidence_current' => true, 'threshold_reached' => true, 'observed_failures' => 3,
            'probe' => ['state' => 'failed', 'installation_id' => $source['installation_id']],
            'plan' => ['id' => 9, 'status' => 'blocked', 'policy_id' => 1, 'policy_revision' => 2,
                'blockers' => ['pricing_unverified'], 'evidence' => ['installation_id' => $source['installation_id'],
                    'required_failures' => 3, 'observed_failures' => 3]]];
        $class = \Keli\Acceptance\MemberNativeGrant::class;
        $this->assertSame(['ready' => true, 'plan_id' => 9], $class::decision($summary, $source, 100, 100));
        $this->assertSame(['ready' => false, 'phase' => 'setup_cooldown', 'next_check_at' => 100], $class::decision($summary, $source, 100, 99));
        foreach (['healthy', 'unknown', 'stale'] as $state) {
            $changed = $summary; $changed['probe']['state'] = $state;
            $this->assertFalse($class::decision($changed, $source, 100, 101)['ready']);
        }
        foreach (['evidence_current', 'threshold_reached'] as $key) {
            $changed = $summary; $changed[$key] = false;
            $this->assertFalse($class::decision($changed, $source, 100, 101)['ready']);
        }
        foreach (['cooldown_active', 'credential_capacity_full', 'permission_revoked', 'unknown'] as $code) {
            $changed = $summary; $changed['plan']['blockers'][] = $code;
            $this->assertSame(['ready' => false, 'phase' => 'source_plan_blocked', 'blockers' => [$code]], $class::decision($changed, $source, 100, 101));
        }
        foreach (['probe_identity', 'plan_identity', 'plan_policy', 'plan_revision', 'threshold', 'count', 'plan_count', 'plan_id'] as $change) {
            $changed = $summary;
            if ($change === 'probe_identity') $changed['probe']['installation_id'] = str_repeat('b', 32);
            if ($change === 'plan_identity') $changed['plan']['evidence']['installation_id'] = str_repeat('b', 32);
            if ($change === 'plan_policy') $changed['plan']['policy_id']++;
            if ($change === 'plan_revision') $changed['plan']['policy_revision']++;
            if ($change === 'threshold') $changed['plan']['evidence']['required_failures'] = 2;
            if ($change === 'count') $changed['observed_failures'] = 2;
            if ($change === 'plan_count') $changed['plan']['evidence']['observed_failures'] = 2;
            if ($change === 'plan_id') $changed['plan']['id'] = '9';
            try { $class::decision($changed, $source, 100, 101); $this->fail('Changed native evidence accepted'); }
            catch (\RuntimeException $error) { $this->assertStringStartsWith('member_native_grant_', $error->getMessage()); }
        }
    }
}
