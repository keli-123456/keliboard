<?php

namespace App\Services;

use App\Models\AgentSiteSetting;
use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\Ticket;
use App\Models\User;
use Plugin\SubscriptionControl\Services\SubscriptionControlEventStore;

class TicketAiContextService
{
    private TicketAiContentSanitizer $sanitizer;

    public function __construct(?TicketAiContentSanitizer $sanitizer = null)
    {
        $this->sanitizer = $sanitizer ?? new TicketAiContentSanitizer();
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Ticket $ticket, int $maxMessages, ?string $instruction): array
    {
        $this->loadContextRelations($ticket);
        $user = $ticket->user instanceof User ? $ticket->user : null;

        return [
            'scope' => $this->scope($ticket),
            'ticket' => [
                'subject' => $this->sanitizer->sanitize((string) $ticket->subject, 300),
                'level' => (int) $ticket->level,
                'status' => (int) $ticket->status,
                'created_at' => $this->timestamp($ticket->created_at),
            ],
            'user' => $this->userSummary($user),
            'subscription' => $this->subscriptionSummary($user),
            'orders' => $this->recentOrders($user),
            'risk' => $this->riskSummary($user),
            'conversation' => $this->conversation($ticket, $maxMessages),
            'instruction' => $this->sanitizer->sanitize((string) ($instruction ?? ''), 1000),
        ];
    }

    private function loadContextRelations(Ticket $ticket): void
    {
        try {
            $ticket->loadMissing([
                'messages' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
                'user.plan',
                'site.setting',
                'site.domains',
                'agentDomain.siteSetting',
            ]);
        } catch (\Throwable) {
            try {
                $ticket->loadMissing(['messages', 'user']);
            } catch (\Throwable) {
                // Context generation must degrade to the fields already loaded on the ticket.
            }
        }
    }

    /** @return array<string, mixed> */
    private function scope(Ticket $ticket): array
    {
        $siteId = $this->positiveInt($ticket->site_id);
        $agentUserId = $this->positiveInt($ticket->agent_user_id);
        $agentDomainId = $this->positiveInt($ticket->agent_domain_id);

        if ($agentUserId !== null) {
            $domain = trim((string) ($ticket->agentDomain?->domain ?? ''));
            $setting = $this->agentSetting($agentUserId, $agentDomainId);
            $brand = trim((string) ($setting?->site_name ?? ''));
            if ($brand === '') {
                $brand = $domain !== '' ? $domain : '代理站点';
            }

            return [
                'type' => 'agent',
                'site_id' => $siteId,
                'agent_user_id' => $agentUserId,
                'agent_domain_id' => $agentDomainId,
                'brand_name' => $this->sanitizer->sanitize($brand, 120),
                'domain' => $this->sanitizer->sanitize($domain, 255),
            ];
        }

        if ($siteId !== null && $ticket->site) {
            $setting = $ticket->site->setting;
            $setting = $setting instanceof SiteSetting && $setting->enabled ? $setting : null;
            $brand = trim((string) ($setting?->site_name ?: $ticket->site->name));
            $domainModel = $ticket->site->domains
                ->where('status', 'active')
                ->sort(function ($left, $right): int {
                    $primaryOrder = (int) $right->is_primary <=> (int) $left->is_primary;
                    if ($primaryOrder !== 0) {
                        return $primaryOrder;
                    }

                    return (int) $left->id <=> (int) $right->id;
                })
                ->first();

            return [
                'type' => 'site',
                'site_id' => $siteId,
                'agent_user_id' => null,
                'agent_domain_id' => null,
                'brand_name' => $this->sanitizer->sanitize($brand, 120),
                'domain' => $this->sanitizer->sanitize((string) ($domainModel?->domain ?? ''), 255),
            ];
        }

        return [
            'type' => 'platform',
            'site_id' => null,
            'agent_user_id' => null,
            'agent_domain_id' => null,
            'brand_name' => $this->sanitizer->sanitize((string) admin_setting('app_name', 'XBoard'), 120),
            'domain' => $this->host((string) admin_setting('app_url', '')),
        ];
    }

    private function agentSetting(int $agentUserId, ?int $agentDomainId): ?AgentSiteSetting
    {
        if (!$this->hasTable('v2_agent_site_setting')) {
            return null;
        }

        try {
            if ($agentDomainId !== null) {
                $domainSetting = AgentSiteSetting::query()
                    ->where('agent_user_id', $agentUserId)
                    ->where('agent_domain_id', $agentDomainId)
                    ->where('enabled', true)
                    ->first();
                if ($domainSetting) {
                    return $domainSetting;
                }
            }

            return AgentSiteSetting::query()
                ->where('agent_user_id', $agentUserId)
                ->whereNull('agent_domain_id')
                ->where('enabled', true)
                ->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private function userSummary(?User $user): array
    {
        if (!$user) {
            return [];
        }

        return [
            'user_ref' => 'user-' . (int) $user->id,
            'banned' => (bool) $user->banned,
            'registered_at' => $this->timestamp($user->created_at),
        ];
    }

    /** @return array<string, mixed> */
    private function subscriptionSummary(?User $user): array
    {
        if (!$user) {
            return [];
        }

        $total = max(0, (int) $user->transfer_enable);
        $used = max(0, (int) $user->u + (int) $user->d);

        return [
            'plan_name' => $this->sanitizer->sanitize((string) ($user->plan?->name ?? ''), 120),
            'expired_at' => $this->timestamp($user->expired_at),
            'transfer_enable_bytes' => $total,
            'used_bytes' => $used,
            'remaining_bytes' => max(0, $total - $used),
            'speed_limit_mbps' => $user->speed_limit !== null ? (int) $user->speed_limit : null,
            'device_limit' => $user->device_limit !== null ? (int) $user->device_limit : null,
            'auto_renew_enabled' => (bool) $user->auto_renew_enable,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function recentOrders(?User $user): array
    {
        if (!$user || !$this->hasTable('v2_order')) {
            return [];
        }

        try {
            return Order::query()
                ->with($this->hasTable('v2_plan') ? ['plan:id,name'] : [])
                ->where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(3)
                ->get()
                ->map(fn (Order $order): array => [
                    'plan_name' => $this->sanitizer->sanitize((string) ($order->plan?->name ?? ''), 120),
                    'period' => $this->sanitizer->sanitize((string) $order->period, 40),
                    'type' => (int) $order->type,
                    'status' => (int) $order->status,
                    'total_amount' => (int) $order->total_amount,
                    'created_at' => $this->timestamp($order->created_at),
                    'paid_at' => $this->timestamp($order->paid_at),
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<string, mixed> */
    private function riskSummary(?User $user): array
    {
        $empty = [
            'available' => false,
            'risk_level' => 'none',
            'risk_score' => 0,
            'event_count' => 0,
            'reset_count' => 0,
            'last_trigger_at' => null,
            'event_codes' => [],
            'actions' => [],
        ];
        if (!$user) {
            return $empty;
        }

        try {
            $store = new SubscriptionControlEventStore();
            if (!$store->available()) {
                return $empty;
            }
            $events = array_values(array_filter(
                $store->recent(100, (string) $user->email),
                fn (array $event): bool => (int) ($event['user_id'] ?? 0) === (int) $user->id
            ));
        } catch (\Throwable) {
            return $empty;
        }

        if ($events === []) {
            return array_merge($empty, ['available' => true]);
        }

        $maxScore = max(array_map(fn (array $event): int => (int) ($event['risk_score'] ?? 0), $events));
        $resets = count(array_filter(
            $events,
            fn (array $event): bool => in_array((string) ($event['action'] ?? ''), ['reset_token', 'reset_token_uuid'], true)
        ));

        return [
            'available' => true,
            'risk_level' => $maxScore >= 60 ? 'high' : ($maxScore >= 20 ? 'medium' : 'low'),
            'risk_score' => $maxScore,
            'event_count' => count($events),
            'reset_count' => $resets,
            'last_trigger_at' => (int) ($events[0]['created_at'] ?? 0) ?: null,
            'event_codes' => $this->uniqueStrings(array_column($events, 'code')),
            'actions' => $this->uniqueStrings(array_column($events, 'action')),
        ];
    }

    /** @return array<int, array{role:string, content:string}> */
    private function conversation(Ticket $ticket, int $maxMessages): array
    {
        $messages = $ticket->messages->map(fn ($message): array => [
            'role' => (int) $message->user_id === (int) $ticket->user_id ? 'user' : 'assistant',
            'content' => (string) $message->message,
        ]);

        return $this->sanitizer->sanitizeConversation($messages, max(1, $maxMessages));
    }

    /** @return array<int, string> */
    private function uniqueStrings(array $values): array
    {
        $values = array_map(
            fn ($value): string => $this->sanitizer->sanitize(trim((string) $value), 80),
            $values
        );

        return array_slice(array_values(array_unique(array_filter($values))), 0, 8);
    }

    private function timestamp(mixed $value): ?int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function host(string $url): string
    {
        return $this->sanitizer->sanitize((string) (parse_url($url, PHP_URL_HOST) ?: ''), 255);
    }

    private function hasTable(string $table): bool
    {
        try {
            return app('db')->connection()->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }
}
