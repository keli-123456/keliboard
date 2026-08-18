<?php

namespace App\Services;

use App\Models\Knowledge;
use App\Models\SubscriptionControlAiReview;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AiCenterService
{
    public function __construct(
        private readonly TicketAiAssistantService $ticketAi,
        private readonly AiDiagnosticService $diagnostics,
    ) {
    }

    /** @return array<string, mixed> */
    public function overview(int $days = 7): array
    {
        $days = max(1, min(90, $days));
        $settings = $this->safe(fn (): array => $this->ticketAi->publicSettings(), []);
        $provider = $this->safe(fn (): array => $this->ticketAi->capabilities(), [
            'enabled' => false,
            'configured' => false,
            'available' => false,
            'reason' => 'unavailable',
            'circuit_open_until' => null,
            'consecutive_failures' => 0,
        ]);
        $ticketStats = $this->safe(
            fn (): array => $this->ticketAi->stats($days),
            $this->emptyTicketStats($days)
        );

        $modules = [
            'ticket_assistant' => [
                'state' => $this->ticketState($provider),
                'enabled' => (bool) ($settings['ticket_ai_enable'] ?? false),
                'auto_reply_enabled' => (bool) ($settings['ticket_ai_auto_reply_enable'] ?? false),
                'knowledge_enabled' => (bool) ($settings['ticket_ai_knowledge_enable'] ?? false),
                'stats' => $ticketStats,
            ],
            'subscription_risk' => $this->riskStatus(),
            'system_diagnostics' => $this->diagnosticStatus(),
            'knowledge_base' => $this->knowledgeStatus(),
        ];

        $ready = count(array_filter(
            $modules,
            static fn (array $module): bool => ($module['state'] ?? null) === 'ready'
        ));

        return [
            'generated_at' => time(),
            'window_days' => $days,
            'provider' => [
                'enabled' => (bool) ($provider['enabled'] ?? false),
                'configured' => (bool) ($provider['configured'] ?? false),
                'available' => (bool) ($provider['available'] ?? false),
                'reason' => $provider['reason'] ?? null,
                'model' => (string) ($settings['ticket_ai_model'] ?? ''),
                'provider_host' => $this->providerHost((string) ($settings['ticket_ai_base_url'] ?? '')),
                'circuit_open_until' => $provider['circuit_open_until'] ?? null,
                'consecutive_failures' => (int) ($provider['consecutive_failures'] ?? 0),
            ],
            'summary' => [
                'ready_modules' => $ready,
                'total_modules' => count($modules),
                'requests' => (int) ($ticketStats['requests'] ?? 0),
                'success_rate' => (float) ($ticketStats['success_rate'] ?? 0),
                'estimated_cost' => (float) ($ticketStats['estimated_cost'] ?? 0),
                'estimated_cost_currency' => (string) ($ticketStats['estimated_cost_currency'] ?? 'CNY'),
            ],
            'integration' => [
                'shared_provider_modules' => ['ticket_assistant', 'subscription_risk'],
                'diagnostics_mode' => 'local_rules',
                'direct_enforcement' => false,
                'manual_approval_required' => true,
            ],
            'modules' => $modules,
        ];
    }

    /** @return array<string, mixed> */
    private function riskStatus(): array
    {
        if (!Schema::hasTable('v2_subscription_control_ai_review')) {
            return [
                'state' => 'migration_required',
                'available' => false,
                'ai_ready' => false,
                'latest_review' => null,
                'manual_approval_required' => true,
            ];
        }

        return $this->safe(function (): array {
            $latest = SubscriptionControlAiReview::query()->latest('id')->first();
            $aiReady = (bool) ($this->ticketAi->capabilities()['available'] ?? false);

            return [
                'state' => $aiReady ? 'ready' : 'needs_configuration',
                'available' => true,
                'ai_ready' => $aiReady,
                'latest_review' => $latest ? [
                    'id' => (int) $latest->id,
                    'status' => (string) $latest->status,
                    'window_days' => (int) $latest->window_days,
                    'event_count' => (int) $latest->event_count,
                    'health_score' => (int) $latest->health_score,
                    'error_code' => $latest->error_code,
                    'generated_at' => $latest->generated_at !== null ? (int) $latest->generated_at : null,
                    'applied_at' => $latest->applied_at !== null ? (int) $latest->applied_at : null,
                    'rolled_back_at' => $latest->rolled_back_at !== null ? (int) $latest->rolled_back_at : null,
                ] : null,
                'manual_approval_required' => true,
            ];
        }, [
            'state' => 'unavailable',
            'available' => false,
            'ai_ready' => false,
            'latest_review' => null,
            'manual_approval_required' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function diagnosticStatus(): array
    {
        $overview = $this->safe(fn (): array => $this->diagnostics->overview('platform'), []);
        if ($overview === []) {
            return [
                'state' => 'unavailable',
                'enabled' => false,
                'schedule_enabled' => false,
                'mode' => 'local_rules',
                'latest_report' => null,
            ];
        }

        $settings = (array) ($overview['settings'] ?? []);
        $report = is_array($overview['report'] ?? null) ? $overview['report'] : null;
        $enabled = (bool) ($settings['enabled'] ?? false);

        return [
            'state' => $enabled ? 'ready' : 'disabled',
            'enabled' => $enabled,
            'schedule_enabled' => (bool) ($settings['schedule_enabled'] ?? false),
            'mode' => 'local_rules',
            'latest_report' => $report ? [
                'id' => (int) ($report['id'] ?? 0),
                'status' => (string) ($report['status'] ?? ''),
                'score' => (int) ($report['score'] ?? 0),
                'finding_count' => count((array) ($report['findings'] ?? [])),
                'generated_at' => (int) ($report['generated_at'] ?? 0),
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function knowledgeStatus(): array
    {
        if (!Schema::hasTable('v2_knowledge')) {
            return [
                'state' => 'migration_required',
                'available' => false,
                'total' => 0,
                'active' => 0,
                'official' => 0,
            ];
        }

        return $this->safe(fn (): array => [
            'state' => 'ready',
            'available' => true,
            'total' => Knowledge::query()->count(),
            'active' => Knowledge::query()->where('show', 1)->count(),
            'official' => Knowledge::query()->where('source', Knowledge::SOURCE_OFFICIAL)->count(),
        ], [
            'state' => 'unavailable',
            'available' => false,
            'total' => 0,
            'active' => 0,
            'official' => 0,
        ]);
    }

    /** @param array<string, mixed> $provider */
    private function ticketState(array $provider): string
    {
        if ((bool) ($provider['available'] ?? false)) {
            return 'ready';
        }

        return ($provider['reason'] ?? null) === 'disabled' ? 'disabled' : 'needs_configuration';
    }

    private function providerHost(string $baseUrl): string
    {
        return $baseUrl === '' ? '' : (string) (parse_url($baseUrl, PHP_URL_HOST) ?: '');
    }

    /** @return array<string, mixed> */
    private function emptyTicketStats(int $days): array
    {
        return [
            'days' => $days,
            'generated' => 0,
            'sent' => 0,
            'needs_human' => 0,
            'adoption_rate' => 0.0,
            'knowledge_gap_count' => 0,
            'requests' => 0,
            'successful_requests' => 0,
            'success_rate' => 0.0,
            'average_latency_ms' => 0,
            'total_tokens' => 0,
            'estimated_cost' => 0.0,
            'estimated_cost_currency' => 'CNY',
            'top_errors' => [],
        ];
    }

    private function safe(callable $callback, array $fallback): array
    {
        try {
            $result = $callback();
            return is_array($result) ? $result : $fallback;
        } catch (Throwable) {
            return $fallback;
        }
    }
}
