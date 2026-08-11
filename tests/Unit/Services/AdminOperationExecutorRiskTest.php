<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AdminOperationExecutor;
use ReflectionClass;
use Tests\TestCase;

final class AdminOperationExecutorRiskTest extends TestCase
{
    public function test_machine_batch_binding_risk_matches_transfer_and_replace_behavior(): void
    {
        /** @var AdminOperationExecutor $executor */
        $executor = (new ReflectionClass(AdminOperationExecutor::class))->newInstanceWithoutConstructor();

        $this->assertSame('danger', $executor->riskLevel(AdminOperationExecutor::MACHINE_BATCH_BIND, [
            'mode' => 'append',
            'allow_transfer' => true,
        ]));
        $this->assertSame('warning', $executor->riskLevel(AdminOperationExecutor::MACHINE_BATCH_BIND, [
            'mode' => 'replace',
            'allow_transfer' => false,
        ]));
        $this->assertSame('normal', $executor->riskLevel(AdminOperationExecutor::MACHINE_BATCH_BIND, [
            'mode' => 'append',
            'allow_transfer' => false,
        ]));
    }

    public function test_existing_operation_risk_levels_remain_stable(): void
    {
        /** @var AdminOperationExecutor $executor */
        $executor = (new ReflectionClass(AdminOperationExecutor::class))->newInstanceWithoutConstructor();

        $this->assertSame('danger', $executor->riskLevel(AdminOperationExecutor::USER_DELETE));
        $this->assertSame('warning', $executor->riskLevel(AdminOperationExecutor::USER_RESET_TRAFFIC));
        $this->assertSame('normal', $executor->riskLevel(AdminOperationExecutor::USER_SET_BALANCE));
    }
}
