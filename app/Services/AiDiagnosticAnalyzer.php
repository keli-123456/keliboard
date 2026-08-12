<?php

declare(strict_types=1);

namespace App\Services;

final class AiDiagnosticAnalyzer
{
    /** @param array<string, mixed> $metrics */
    public function analyze(array $metrics): array
    {
        $findings = [];
        $business = (array) ($metrics['business'] ?? []);
        $payment = (array) ($metrics['payment'] ?? []);
        $referral = (array) ($metrics['referral'] ?? []);
        $infrastructure = (array) ($metrics['infrastructure'] ?? []);

        $this->dropFinding($findings, 'business_income_drop', 'business', $business, 'income', 1000, 0.60, 0.35, 'money');
        $this->dropFinding($findings, 'business_registration_drop', 'business', $business, 'new_users', 5, 0.50, 0.25, 'count');
        $this->dropFinding($findings, 'business_traffic_drop', 'business', $business, 'traffic_bytes', 1073741824, 0.30, 0.10, 'bytes');

        $tickets = (int) ($business['tickets_current'] ?? 0);
        $ticketBaseline = (float) ($business['tickets_baseline'] ?? 0);
        if ($tickets >= 5 && $tickets > max(1, $ticketBaseline) * 2) {
            $findings[] = $this->finding(
                'business_ticket_surge',
                'business',
                $tickets > max(1, $ticketBaseline) * 4 ? 'critical' : 'warning',
                $tickets,
                $ticketBaseline,
                'count'
            );
        }

        $orders = (int) ($payment['orders_current'] ?? 0);
        $successRate = (float) ($payment['success_rate_current'] ?? 0);
        $successBaseline = (float) ($payment['success_rate_baseline'] ?? 0);
        if ($orders >= 10 && $successRate < 0.70) {
            $findings[] = $this->finding(
                'payment_success_low',
                'payment',
                $successRate < 0.40 ? 'critical' : 'warning',
                $successRate,
                $successBaseline,
                'ratio'
            );
        }

        $pendingRate = (float) ($payment['pending_rate_current'] ?? 0);
        $pendingBaseline = (float) ($payment['pending_rate_baseline'] ?? 0);
        if ($orders >= 10 && $pendingRate >= 0.25 && $pendingRate > max(0.01, $pendingBaseline) * 1.5) {
            $findings[] = $this->finding(
                'payment_pending_surge',
                'payment',
                $pendingRate >= 0.50 ? 'critical' : 'warning',
                $pendingRate,
                $pendingBaseline,
                'ratio'
            );
        }

        $invites = (int) ($referral['invites_current'] ?? 0);
        $inviteBaseline = (float) ($referral['invites_baseline'] ?? 0);
        if ($invites >= 10 && $invites > max(1, $inviteBaseline) * 3) {
            $findings[] = $this->finding(
                'referral_volume_surge',
                'referral',
                $invites > max(1, $inviteBaseline) * 5 ? 'critical' : 'warning',
                $invites,
                $inviteBaseline,
                'count'
            );
        }

        $topShare = (float) ($referral['top_inviter_share'] ?? 0);
        if ($invites >= 10 && $topShare >= 0.60) {
            $finding = $this->finding(
                'referral_concentration',
                'referral',
                $topShare >= 0.80 ? 'critical' : 'warning',
                $topShare,
                0.60,
                'ratio'
            );
            $finding['evidence']['subject_id'] = (int) ($referral['top_inviter_id'] ?? 0);
            $findings[] = $finding;
        }

        $conversion = (float) ($referral['conversion_current'] ?? 0);
        $conversionBaseline = (float) ($referral['conversion_baseline'] ?? 0);
        if ($invites >= 10 && $conversion < 0.05 && $conversionBaseline >= 0.08) {
            $findings[] = $this->finding(
                'referral_low_conversion',
                'referral',
                'warning',
                $conversion,
                $conversionBaseline,
                'ratio'
            );
        }

        $pendingCommission = (int) ($referral['pending_commission_amount'] ?? 0);
        $income = (int) ($business['income_current'] ?? 0);
        if ($pendingCommission > 0 && $income > 0 && $pendingCommission > $income * 0.5) {
            $findings[] = $this->finding(
                'referral_commission_exposure',
                'referral',
                $pendingCommission > $income ? 'critical' : 'warning',
                $pendingCommission,
                $income,
                'money'
            );
        }

        $enabledNodes = (int) ($infrastructure['enabled_nodes'] ?? 0);
        $offlineNodes = (int) ($infrastructure['offline_nodes'] ?? 0);
        if ($offlineNodes > 0) {
            $offlineRatio = $enabledNodes > 0 ? $offlineNodes / $enabledNodes : 1.0;
            $findings[] = $this->finding(
                'infrastructure_nodes_offline',
                'infrastructure',
                $offlineRatio >= 0.30 ? 'critical' : 'warning',
                $offlineNodes,
                $enabledNodes,
                'count'
            );
        }

        $downDomains = (int) ($infrastructure['down_domains'] ?? 0);
        $warningDomains = (int) ($infrastructure['warning_domains'] ?? 0);
        if ($downDomains > 0 || $warningDomains > 0) {
            $findings[] = $this->finding(
                'infrastructure_domain_unhealthy',
                'infrastructure',
                $downDomains > 0 ? 'critical' : 'warning',
                $downDomains,
                $warningDomains,
                'count'
            );
        }

        $failedTasks = (int) ($infrastructure['failed_tasks'] ?? 0);
        if ($failedTasks > 0) {
            $findings[] = $this->finding(
                'infrastructure_failed_tasks',
                'infrastructure',
                $failedTasks >= 5 ? 'critical' : 'warning',
                $failedTasks,
                0,
                'count'
            );
        }

        usort($findings, static fn (array $left, array $right): int =>
            (self::severityWeight($right['severity']) <=> self::severityWeight($left['severity']))
                ?: strcmp((string) $left['key'], (string) $right['key'])
        );

        $critical = count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'critical'));
        $warning = count(array_filter($findings, static fn (array $finding): bool => $finding['severity'] === 'warning'));
        $score = max(0, 100 - ($critical * 25) - ($warning * 10));

        return [
            'status' => $critical > 0 ? 'critical' : ($warning > 0 ? 'warning' : 'healthy'),
            'score' => $score,
            'summary' => [
                'critical' => $critical,
                'warning' => $warning,
                'healthy_modules' => max(0, 4 - count(array_unique(array_column($findings, 'module')))),
                'finding_count' => count($findings),
            ],
            'findings' => $findings,
        ];
    }

    /** @param array<int, array<string, mixed>> $findings @param array<string, mixed> $metrics */
    private function dropFinding(
        array &$findings,
        string $key,
        string $module,
        array $metrics,
        string $metric,
        float $minimumBaseline,
        float $warningRatio,
        float $criticalRatio,
        string $unit
    ): void {
        $current = (float) ($metrics[$metric . '_current'] ?? 0);
        $baseline = (float) ($metrics[$metric . '_baseline'] ?? 0);
        if ($baseline < $minimumBaseline || $current >= $baseline * $warningRatio) {
            return;
        }

        $findings[] = $this->finding(
            $key,
            $module,
            $current < $baseline * $criticalRatio ? 'critical' : 'warning',
            $current,
            $baseline,
            $unit
        );
    }

    private function finding(string $key, string $module, string $severity, float|int $current, float|int $baseline, string $unit): array
    {
        $change = $baseline != 0.0 ? (($current - $baseline) / abs($baseline)) * 100 : null;

        return [
            'key' => $key,
            'module' => $module,
            'severity' => $severity,
            'confidence' => $baseline != 0.0 ? 'high' : 'medium',
            'evidence' => [
                'current' => $current,
                'baseline' => $baseline,
                'unit' => $unit,
                'change_percent' => $change !== null ? round($change, 1) : null,
            ],
        ];
    }

    private static function severityWeight(string $severity): int
    {
        return $severity === 'critical' ? 2 : ($severity === 'warning' ? 1 : 0);
    }
}
