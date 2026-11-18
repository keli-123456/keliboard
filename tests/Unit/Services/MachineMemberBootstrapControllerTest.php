<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use Keli\Acceptance\MemberBootstrapController;
use Keli\Acceptance\MemberCliAuthority;
use PHPUnit\Framework\TestCase;

final class MachineMemberBootstrapControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../../tools/machine-acceptance/member_cli_authority.php';
        require_once __DIR__ . '/../../../tools/machine-acceptance/member_bootstrap_controller.php';
    }

    private function issue(): array
    {
        $task = '00000000-0000-4000-8000-000000000071';
        return ['operation' => 'setup-issue', 'control' => ['run_id' => MemberCliAuthority::RUN,
            'external_id' => '100000', 'task_id' => $task, 'label' => 'keli-' . str_replace('-', '', $task),
            'marker' => 'keli-create-' . str_replace('-', '', $task), 'region' => 'sg-sin-2',
            'size' => 'g6-standard-2', 'image' => 'linode/debian12', 'provider_created_at' => '2026-11-17T00:00:00Z']];
    }

    public function test_fixed_operations_have_no_secret_scope_or_deadline_override(): void
    {
        foreach (['import', 'readback', 'configure', 'configuration-read', 'setup-read', 'stop'] as $operation) {
            MemberBootstrapController::validateRequest(['operation' => $operation]);
            $this->addToAssertionCount(1);
            foreach (['started_at', 'deadline_at', 'scope', 'credentials', 'command', 'control'] as $key)
                $this->denied(['operation' => $operation, $key => 'unexpected']);
        }
        MemberBootstrapController::validateRequest($this->issue()); $this->addToAssertionCount(1);
        foreach ([[], ['operation' => null], ['operation' => []], ['operation' => 'restart'],
            ['operation' => 'setup-issue'], $this->issue() + ['deadline_at' => PHP_INT_MAX]] as $request) $this->denied($request);
    }

    public function test_control_request_requires_exact_native_task_pin_and_approved_plan(): void
    {
        foreach (['run_id' => 'foreign', 'external_id' => '../100000', 'task_id' => 'wrong', 'label' => 'foreign',
            'marker' => 'foreign', 'region' => 'us-east', 'size' => 'g6-standard-4', 'image' => 'other',
            'provider_created_at' => 'yesterday'] as $key => $value) {
            $request = $this->issue(); $request['control'][$key] = $value; $this->denied($request);
        }
        foreach ([null, 'wrong', [], ['extra' => 'wrong'] + $this->issue()['control']] as $control)
            $this->denied(['operation' => 'setup-issue', 'control' => $control]);
    }

    private function denied(array $request): void
    {
        try { MemberBootstrapController::validateRequest($request); self::fail('unexpected bootstrap scope accepted'); }
        catch (\RuntimeException $error) { self::assertMatchesRegularExpression('/^member_import_(request|control)_invalid$/D', $error->getMessage()); }
    }
}
