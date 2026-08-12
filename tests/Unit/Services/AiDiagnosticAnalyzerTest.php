<?php

namespace Tests\Unit\Services;

use App\Services\AiDiagnosticAnalyzer;
use PHPUnit\Framework\TestCase;

class AiDiagnosticAnalyzerTest extends TestCase
{
    public function test_it_reports_healthy_when_metrics_are_stable(): void
    {
        $result = (new AiDiagnosticAnalyzer())->analyze($this->metrics());

        $this->assertSame('healthy', $result['status']);
        $this->assertSame(100, $result['score']);
        $this->assertSame([], $result['findings']);
    }

    public function test_it_detects_payment_and_referral_anomalies_with_evidence(): void
    {
        $metrics = $this->metrics();
        $metrics['payment'] = [
            'orders_current' => 20,
            'success_rate_current' => 0.30,
            'success_rate_baseline' => 0.91,
            'pending_rate_current' => 0.60,
            'pending_rate_baseline' => 0.05,
        ];
        $metrics['referral'] = [
            'invites_current' => 30,
            'invites_baseline' => 4,
            'top_inviter_share' => 0.90,
            'top_inviter_id' => 42,
            'conversion_current' => 0.01,
            'conversion_baseline' => 0.20,
            'pending_commission_amount' => 12000,
        ];

        $result = (new AiDiagnosticAnalyzer())->analyze($metrics);
        $keys = array_column($result['findings'], 'key');

        $this->assertSame('critical', $result['status']);
        $this->assertContains('payment_success_low', $keys);
        $this->assertContains('payment_pending_surge', $keys);
        $this->assertContains('referral_volume_surge', $keys);
        $this->assertContains('referral_concentration', $keys);
        $this->assertSame(42, collect($result['findings'])->firstWhere('key', 'referral_concentration')['evidence']['subject_id']);
    }

    public function test_low_volume_does_not_trigger_ratio_noise(): void
    {
        $metrics = $this->metrics();
        $metrics['payment'] = [
            'orders_current' => 2,
            'success_rate_current' => 0,
            'success_rate_baseline' => 1,
            'pending_rate_current' => 1,
            'pending_rate_baseline' => 0,
        ];
        $metrics['referral']['invites_current'] = 2;
        $metrics['referral']['top_inviter_share'] = 1;

        $result = (new AiDiagnosticAnalyzer())->analyze($metrics);
        $keys = array_column($result['findings'], 'key');

        $this->assertNotContains('payment_success_low', $keys);
        $this->assertNotContains('payment_pending_surge', $keys);
        $this->assertNotContains('referral_concentration', $keys);
    }

    private function metrics(): array
    {
        return [
            'business' => [
                'income_current' => 10000,
                'income_baseline' => 10000,
                'new_users_current' => 20,
                'new_users_baseline' => 20,
                'traffic_bytes_current' => 2 * 1073741824,
                'traffic_bytes_baseline' => 2 * 1073741824,
                'tickets_current' => 2,
                'tickets_baseline' => 2,
            ],
            'payment' => [
                'orders_current' => 20,
                'success_rate_current' => 0.90,
                'success_rate_baseline' => 0.90,
                'pending_rate_current' => 0.05,
                'pending_rate_baseline' => 0.05,
            ],
            'referral' => [
                'invites_current' => 8,
                'invites_baseline' => 8,
                'top_inviter_share' => 0.25,
                'top_inviter_id' => 5,
                'conversion_current' => 0.25,
                'conversion_baseline' => 0.25,
                'pending_commission_amount' => 1000,
            ],
            'infrastructure' => [
                'enabled_nodes' => 10,
                'offline_nodes' => 0,
                'down_domains' => 0,
                'warning_domains' => 0,
                'failed_tasks' => 0,
            ],
        ];
    }
}
