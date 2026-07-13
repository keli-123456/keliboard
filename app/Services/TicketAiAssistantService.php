<?php

namespace App\Services;

use App\Exceptions\TicketAiProviderException;
use App\Models\Knowledge;
use App\Models\Ticket;
use App\Models\TicketAiRequestLog;
use App\Models\TicketAiSuggestion;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TicketAiAssistantService
{
    private const API_KEY_SETTING = 'ticket_ai_api_key';
    private const API_KEY_MASK = '********';
    private const CATEGORY_OPTIONS = [
        '客户端连接',
        '订阅与节点',
        '套餐订单',
        '支付退款',
        '账号安全',
        '流量异常',
        '服务器故障',
        '其他',
    ];
    private const HUMAN_REVIEW_CATEGORIES = ['支付退款', '账号安全', '服务器故障'];

    private TicketAiContextService $contextService;
    private TicketAiContentSanitizer $sanitizer;
    private TicketAiProviderClient $providerClient;

    public function __construct(
        ?TicketAiContextService $contextService = null,
        ?TicketAiContentSanitizer $sanitizer = null,
        ?TicketAiProviderClient $providerClient = null
    ) {
        $this->sanitizer = $sanitizer ?? new TicketAiContentSanitizer();
        $this->contextService = $contextService ?? new TicketAiContextService($this->sanitizer);
        $this->providerClient = $providerClient ?? new TicketAiProviderClient();
    }

    public function publicSettings(): array
    {
        $settings = $this->settings();

        return [
            'ticket_ai_enable' => $settings['enabled'],
            'ticket_ai_base_url' => $settings['base_url'],
            'ticket_ai_model' => $settings['model'],
            'ticket_ai_temperature' => $settings['temperature'],
            'ticket_ai_max_messages' => $settings['max_messages'],
            'ticket_ai_knowledge_enable' => $settings['knowledge_enable'],
            'ticket_ai_max_tokens' => $settings['max_tokens'],
            'ticket_ai_timeout' => $settings['timeout'],
            'ticket_ai_json_mode' => $settings['json_mode'],
            'ticket_ai_log_retention_days' => $settings['log_retention_days'],
            'ticket_ai_system_prompt' => $settings['system_prompt'],
            'ticket_ai_api_key' => '',
            'ticket_ai_api_key_set' => $this->apiKey() !== '',
        ];
    }

    public function prepareSettingsForSave(array $data): array
    {
        if (array_key_exists(self::API_KEY_SETTING, $data)) {
            $value = trim((string) ($data[self::API_KEY_SETTING] ?? ''));
            unset($data[self::API_KEY_SETTING]);

            if ($value === '__CLEAR__') {
                $data[self::API_KEY_SETTING] = '';
            } elseif ($value !== '' && $value !== self::API_KEY_MASK) {
                $data[self::API_KEY_SETTING] = Crypt::encryptString($value);
            }
        }

        return $data;
    }

    /** @return array{enabled:bool,configured:bool,available:bool,reason:?string} */
    public function capabilities(): array
    {
        $settings = $this->settings();
        $reason = null;
        if (!$settings['enabled']) {
            $reason = 'disabled';
        } elseif ($settings['base_url'] === '') {
            $reason = 'missing_base_url';
        } elseif ($settings['model'] === '') {
            $reason = 'missing_model';
        } elseif ($this->apiKey() === '') {
            $reason = 'missing_api_key';
        }

        $configured = $settings['base_url'] !== ''
            && $settings['model'] !== ''
            && $this->apiKey() !== '';

        return [
            'enabled' => $settings['enabled'],
            'configured' => $configured,
            'available' => $settings['enabled'] && $configured,
            'reason' => $reason,
        ];
    }

    /** @return array{ok:bool,model:string,latency_ms:int} */
    public function testConnection(?int $adminId = null): array
    {
        $settings = $this->settings();
        $this->assertAvailable();
        $providerSettings = array_merge($settings, ['api_key' => $this->apiKey()]);
        $messages = [
            ['role' => 'system', 'content' => 'Return a minimal JSON object.'],
            ['role' => 'user', 'content' => '{"ping":"ok"}'],
        ];
        $startedAt = hrtime(true);

        try {
            $completion = $this->providerClient->complete($providerSettings, $messages);
            $this->recordRequestLog(array_merge(
                $this->platformScopeFields(),
                $this->completionLogFields($completion),
                [
                    'admin_id' => $adminId,
                    'status' => TicketAiRequestLog::STATUS_SUCCESS,
                    'provider_host' => $this->providerHost($settings['base_url']),
                    'model' => $settings['model'],
                ]
            ));

            return [
                'ok' => true,
                'model' => $settings['model'],
                'latency_ms' => (int) $completion['latency_ms'],
            ];
        } catch (TicketAiProviderException $exception) {
            $this->recordRequestLog(array_merge($this->platformScopeFields(), [
                'admin_id' => $adminId,
                'status' => TicketAiRequestLog::STATUS_FAILED,
                'error_code' => $exception->errorCode(),
                'provider_host' => $this->providerHost($settings['base_url']),
                'model' => $settings['model'],
                'latency_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
                'prompt_chars' => $this->messageChars($messages),
            ]));
            throw $exception;
        }
    }

    public function suggest(Ticket $ticket, ?string $instruction = null, ?int $adminId = null): array
    {
        $settings = $this->settings();
        $this->assertAvailable();
        $context = $this->contextService->build($ticket, $settings['max_messages'], $instruction);
        $knowledge = $settings['knowledge_enable'] ? $this->findRelevantKnowledge($ticket) : [];
        $knowledge = $this->sanitizer->sanitizeKnowledge($knowledge);
        $messages = $this->buildMessages($context, $knowledge, $settings);
        $scopeFields = $this->scopeFields((array) ($context['scope'] ?? []));
        $providerSettings = array_merge($settings, ['api_key' => $this->apiKey()]);
        $startedAt = hrtime(true);

        try {
            $completion = $this->providerClient->complete($providerSettings, $messages);
            $result = $this->normalizeAiResult(
                (string) $completion['content'],
                $knowledge,
                is_array($completion['decoded']) ? $completion['decoded'] : null,
                (bool) $completion['structured']
            );
            $suggestion = TicketAiSuggestion::create(array_merge($scopeFields, [
                'ticket_id' => (int) $ticket->id,
                'admin_id' => $adminId,
                'model' => $settings['model'],
                'structured_output' => $result['structured_output'],
                'category' => $result['category'],
                'sentiment' => $result['sentiment'],
                'risk' => $result['risk'],
                'needs_human' => $result['needs_human'],
                'confidence' => $result['confidence'],
                'summary' => $result['summary'],
                'draft' => $result['draft'],
                'draft_hash' => $this->messageHash($result['draft']),
                'instruction' => $this->sanitizer->sanitize((string) ($instruction ?? ''), 1000),
                'knowledge_refs' => $result['knowledge_refs'],
                'matched_knowledge' => $result['matched_knowledge'],
                'status' => TicketAiSuggestion::STATUS_GENERATED,
            ]));

            $this->recordRequestLog(array_merge(
                $scopeFields,
                $this->completionLogFields($completion),
                [
                    'ticket_id' => (int) $ticket->id,
                    'suggestion_id' => (int) $suggestion->id,
                    'admin_id' => $adminId,
                    'status' => TicketAiRequestLog::STATUS_SUCCESS,
                    'provider_host' => $this->providerHost($settings['base_url']),
                    'model' => $settings['model'],
                ]
            ));

            $result['suggestion_id'] = (int) $suggestion->id;
            $result['category_options'] = self::CATEGORY_OPTIONS;
            $result['scope'] = $context['scope'];

            return $result;
        } catch (TicketAiProviderException $exception) {
            $this->recordRequestLog(array_merge($scopeFields, [
                'ticket_id' => (int) $ticket->id,
                'admin_id' => $adminId,
                'status' => TicketAiRequestLog::STATUS_FAILED,
                'error_code' => $exception->errorCode(),
                'provider_host' => $this->providerHost($settings['base_url']),
                'model' => $settings['model'],
                'latency_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
                'prompt_chars' => $this->messageChars($messages),
            ]));
            throw $exception;
        }
    }

    public function recordFeedback(int $suggestionId, int $ticketId, ?int $adminId, string $status): array
    {
        if (!in_array($status, [TicketAiSuggestion::STATUS_INSERTED, TicketAiSuggestion::STATUS_DISCARDED], true)) {
            throw new RuntimeException('AI 建议状态不正确');
        }

        $suggestion = TicketAiSuggestion::query()
            ->where('id', $suggestionId)
            ->where('ticket_id', $ticketId)
            ->first();

        if (!$suggestion) {
            throw new RuntimeException('AI 建议不存在');
        }

        if ($suggestion->admin_id !== null && $adminId !== null && (int) $suggestion->admin_id !== (int) $adminId) {
            throw new RuntimeException('AI 建议不属于当前管理员');
        }

        $now = time();
        $suggestion->status = $status;
        if ($adminId !== null && $suggestion->admin_id === null) {
            $suggestion->admin_id = $adminId;
        }
        if ($status === TicketAiSuggestion::STATUS_INSERTED) {
            $suggestion->inserted_at = $suggestion->inserted_at ?: $now;
        } else {
            $suggestion->discarded_at = $suggestion->discarded_at ?: $now;
        }
        $suggestion->save();

        return [
            'id' => (int) $suggestion->id,
            'status' => (string) $suggestion->status,
        ];
    }

    public function markSent(?int $suggestionId, int $ticketId, int $adminId, TicketMessage $message, string $finalMessage): void
    {
        if (!$suggestionId) {
            return;
        }

        $suggestion = TicketAiSuggestion::query()
            ->where('id', $suggestionId)
            ->where('ticket_id', $ticketId)
            ->first();

        if (!$suggestion) {
            return;
        }
        if ($suggestion->admin_id !== null && (int) $suggestion->admin_id !== $adminId) {
            return;
        }

        $finalHash = $this->messageHash($finalMessage);
        $draftHash = (string) ($suggestion->draft_hash ?: $this->messageHash((string) $suggestion->draft));
        $suggestion->admin_id = $suggestion->admin_id ?: $adminId;
        $suggestion->status = TicketAiSuggestion::STATUS_SENT;
        $suggestion->inserted_at = $suggestion->inserted_at ?: time();
        $suggestion->sent_at = time();
        $suggestion->reply_message_id = (int) $message->id;
        $suggestion->final_message_hash = $finalHash;
        $suggestion->edited = $draftHash !== $finalHash;
        $suggestion->save();
    }

    public function stats(int $days = 7): array
    {
        $days = max(1, min(90, $days));
        $since = time() - ($days * 86400);
        $baseQuery = TicketAiSuggestion::query()->where('created_at', '>=', $since);

        $generated = (clone $baseQuery)->count();
        $inserted = (clone $baseQuery)->whereNotNull('inserted_at')->count();
        $discarded = (clone $baseQuery)->whereNotNull('discarded_at')->count();
        $sent = (clone $baseQuery)->whereNotNull('sent_at')->count();
        $edited = (clone $baseQuery)->where('edited', 1)->count();
        $needsHuman = (clone $baseQuery)->where('needs_human', 1)->count();

        $requests = 0;
        $successfulRequests = 0;
        $averageLatency = 0;
        $totalTokens = 0;
        $topErrors = [];
        if ($this->hasTable('v2_ticket_ai_request_log')) {
            $requestQuery = TicketAiRequestLog::query()->where('created_at', '>=', $since);
            $requests = (clone $requestQuery)->count();
            $successfulRequests = (clone $requestQuery)
                ->where('status', TicketAiRequestLog::STATUS_SUCCESS)
                ->count();
            $averageLatency = $requests > 0
                ? (int) round((float) (clone $requestQuery)->avg('latency_ms'))
                : 0;
            $totalTokens = (int) (clone $requestQuery)->sum('total_tokens');
            $topErrors = $this->groupRequestCounts($requestQuery, 'error_code');
        }

        return [
            'days' => $days,
            'since' => $since,
            'generated' => (int) $generated,
            'inserted' => (int) $inserted,
            'discarded' => (int) $discarded,
            'sent' => (int) $sent,
            'edited' => (int) $edited,
            'needs_human' => (int) $needsHuman,
            'adoption_rate' => $generated > 0 ? round($sent / $generated, 4) : 0.0,
            'edit_rate' => $sent > 0 ? round($edited / $sent, 4) : 0.0,
            'category_options' => self::CATEGORY_OPTIONS,
            'top_categories' => $this->groupSuggestionCounts($baseQuery, 'category'),
            'top_risks' => $this->groupSuggestionCounts($baseQuery, 'risk'),
            'requests' => (int) $requests,
            'successful_requests' => (int) $successfulRequests,
            'success_rate' => $requests > 0 ? round($successfulRequests / $requests, 4) : 0.0,
            'average_latency_ms' => $averageLatency,
            'total_tokens' => $totalTokens,
            'top_errors' => $topErrors,
        ];
    }

    private function settings(): array
    {
        return [
            'enabled' => (bool) admin_setting('ticket_ai_enable', false),
            'base_url' => rtrim(trim((string) admin_setting('ticket_ai_base_url', '')), '/'),
            'model' => trim((string) admin_setting('ticket_ai_model', '')),
            'temperature' => max(0.0, min(1.0, (float) admin_setting('ticket_ai_temperature', 0.2))),
            'max_messages' => max(3, min(30, (int) admin_setting('ticket_ai_max_messages', 12))),
            'knowledge_enable' => (bool) admin_setting('ticket_ai_knowledge_enable', true),
            'max_tokens' => max(128, min(4096, (int) admin_setting('ticket_ai_max_tokens', 800))),
            'timeout' => max(5, min(120, (int) admin_setting('ticket_ai_timeout', 30))),
            'json_mode' => (bool) admin_setting('ticket_ai_json_mode', false),
            'log_retention_days' => max(7, min(365, (int) admin_setting('ticket_ai_log_retention_days', 30))),
            'system_prompt' => trim((string) admin_setting('ticket_ai_system_prompt', $this->defaultSystemPrompt())),
        ];
    }

    private function apiKey(): string
    {
        $value = (string) admin_setting(self::API_KEY_SETTING, '');
        if ($value === '') {
            return '';
        }

        try {
            return trim(Crypt::decryptString($value));
        } catch (\Throwable) {
            return '';
        }
    }

    private function defaultSystemPrompt(): string
    {
        return '你是 Keli 面板的客服工单助手。你只生成给管理员审核的回复草稿，不直接代表平台承诺退款、补偿、封号、解封或支付处理结果。遇到支付、退款、账号安全、封禁、隐私、法律或大面积故障，必须建议人工核查。category 必须从固定分类中选择，risk 只能是 low、medium、high。回答要简洁、礼貌、可执行。请只输出 JSON：summary, category, sentiment, risk, needs_human, confidence, draft, knowledge_refs。';
    }

    /**
     * @return array<int, array{id:int,title:string,category:string,body:string}>
     */
    private function findRelevantKnowledge(Ticket $ticket): array
    {
        $needle = mb_strtolower($ticket->subject . "\n" . $ticket->messages->pluck('message')->implode("\n"));

        return Knowledge::query()
            ->where('show', 1)
            ->select(['id', 'title', 'category', 'body'])
            ->limit(80)
            ->get()
            ->map(function (Knowledge $item) use ($needle) {
                $text = mb_strtolower((string) $item->title . "\n" . (string) $item->category . "\n" . strip_tags((string) $item->body));
                $score = 0;
                foreach ($this->keywords($needle) as $keyword) {
                    if ($keyword !== '' && str_contains($text, $keyword)) {
                        $score++;
                    }
                }

                return [
                    'id' => (int) $item->id,
                    'title' => (string) $item->title,
                    'category' => (string) $item->category,
                    'body' => mb_substr(trim(strip_tags((string) $item->body)), 0, 1200),
                    'score' => $score,
                ];
            })
            ->filter(fn (array $item) => $item['score'] > 0)
            ->sortByDesc('score')
            ->take(5)
            ->map(fn (array $item) => [
                'id' => $item['id'],
                'title' => $item['title'],
                'category' => $item['category'],
                'body' => $item['body'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function keywords(string $text): array
    {
        $parts = preg_split('/[\s,，。！？!?:：;；、\/\\\\\[\]\(\)（）]+/u', mb_strtolower($text)) ?: [];

        return array_values(array_filter(array_unique(array_map('trim', $parts)), fn (string $part) => mb_strlen($part) >= 2));
    }

    /**
     * @param array<int, array{id:int,title:string,category:string,body:string}> $knowledge
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $context
     * @return array<int, array{role:string,content:string}>
     */
    private function buildMessages(array $context, array $knowledge, array $settings): array
    {
        $prompt = json_encode([
            'ticket_context' => $context,
            'relevant_knowledge' => $knowledge,
            'category_options' => self::CATEGORY_OPTIONS,
            'output_contract' => [
                'fields' => ['summary', 'category', 'sentiment', 'risk', 'needs_human', 'confidence', 'draft', 'knowledge_refs'],
                'risk_values' => ['low', 'medium', 'high'],
                'review_required' => true,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            [
                'role' => 'system',
                'content' => $this->sanitizer->sanitize(
                    (string) ($settings['system_prompt'] ?: $this->defaultSystemPrompt()),
                    5000
                ),
            ],
            ['role' => 'user', 'content' => (string) $prompt],
        ];
    }

    /**
     * @param array<int, array{id:int,title:string,category:string,body:string}> $knowledge
     */
    private function normalizeAiResult(
        string $content,
        array $knowledge,
        ?array $decoded = null,
        bool $structured = true
    ): array
    {
        $decoded = $decoded ?? ($structured ? json_decode($content, true) : null);
        if (!is_array($decoded) || !$structured) {
            $decoded = ['draft' => $content];
        }

        $category = $this->normalizeCategory((string) ($decoded['category'] ?? ''));
        $risk = $this->normalizeRisk((string) ($decoded['risk'] ?? ''));
        $needsHuman = (bool) ($decoded['needs_human'] ?? false)
            || $risk === 'high'
            || in_array($category, self::HUMAN_REVIEW_CATEGORIES, true)
            || !$structured;

        return [
            'summary' => $this->sanitizer->sanitize(trim((string) ($decoded['summary'] ?? '')), 1000),
            'category' => $category,
            'sentiment' => $this->sanitizer->sanitize(trim((string) ($decoded['sentiment'] ?? '')), 100),
            'risk' => $risk,
            'needs_human' => $needsHuman,
            'confidence' => $structured ? max(0, min(1, (float) ($decoded['confidence'] ?? 0))) : 0.0,
            'draft' => $this->sanitizer->sanitize(trim((string) ($decoded['draft'] ?? $content)), 5000),
            'knowledge_refs' => is_array($decoded['knowledge_refs'] ?? null) ? array_values($decoded['knowledge_refs']) : [],
            'matched_knowledge' => array_map(fn (array $item) => [
                'id' => $item['id'],
                'title' => $item['title'],
                'category' => $item['category'],
            ], $knowledge),
            'structured_output' => $structured,
        ];
    }

    private function normalizeCategory(string $category): string
    {
        $value = trim($category);
        if ($value === '') {
            return '其他';
        }
        if (in_array($value, self::CATEGORY_OPTIONS, true)) {
            return $value;
        }
        foreach (self::CATEGORY_OPTIONS as $option) {
            if ($option !== '其他' && (str_contains($value, $option) || str_contains($option, $value))) {
                return $option;
            }
        }

        return '其他';
    }

    private function normalizeRisk(string $risk): string
    {
        $value = mb_strtolower(trim($risk));
        if (in_array($value, ['high', '高', '高风险'], true)) {
            return 'high';
        }
        if (in_array($value, ['medium', 'med', '中', '中风险'], true)) {
            return 'medium';
        }
        return 'low';
    }

    private function assertAvailable(): void
    {
        $capabilities = $this->capabilities();
        if ($capabilities['available']) {
            return;
        }

        throw new RuntimeException(match ($capabilities['reason']) {
            'disabled' => 'AI 工单助手未启用',
            'missing_api_key' => 'AI API Key 未配置',
            'missing_base_url' => 'AI 接口地址未配置',
            'missing_model' => 'AI 模型未配置',
            default => 'AI 工单助手配置不完整',
        });
    }

    /** @return array<string, mixed> */
    private function scopeFields(array $scope): array
    {
        $type = in_array(($scope['type'] ?? null), ['platform', 'site', 'agent'], true)
            ? (string) $scope['type']
            : 'platform';

        return [
            'scope_type' => $type,
            'site_id' => $this->positiveIntOrNull($scope['site_id'] ?? null),
            'agent_user_id' => $this->positiveIntOrNull($scope['agent_user_id'] ?? null),
            'agent_domain_id' => $this->positiveIntOrNull($scope['agent_domain_id'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function platformScopeFields(): array
    {
        return $this->scopeFields(['type' => 'platform']);
    }

    /** @return array<string, int> */
    private function completionLogFields(array $completion): array
    {
        return [
            'latency_ms' => max(0, (int) ($completion['latency_ms'] ?? 0)),
            'input_tokens' => max(0, (int) ($completion['input_tokens'] ?? 0)),
            'output_tokens' => max(0, (int) ($completion['output_tokens'] ?? 0)),
            'total_tokens' => max(0, (int) ($completion['total_tokens'] ?? 0)),
            'prompt_chars' => max(0, (int) ($completion['prompt_chars'] ?? 0)),
            'response_chars' => max(0, (int) ($completion['response_chars'] ?? 0)),
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function recordRequestLog(array $attributes): void
    {
        if (!$this->hasTable('v2_ticket_ai_request_log')) {
            return;
        }

        try {
            TicketAiRequestLog::record($attributes);
        } catch (\Throwable) {
            // AI assistance must remain usable when operational logging is unavailable.
        }
    }

    private function providerHost(string $baseUrl): string
    {
        return trim((string) (parse_url($baseUrl, PHP_URL_HOST) ?: ''));
    }

    /** @param array<int, array{role:string,content:string}> $messages */
    private function messageChars(array $messages): int
    {
        return array_sum(array_map(
            static fn (array $message): int => mb_strlen((string) ($message['content'] ?? '')),
            $messages
        ));
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function hasTable(string $table): bool
    {
        try {
            return app('db')->connection()->getSchemaBuilder()->hasTable($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function messageHash(string $message): string
    {
        $normalized = trim(str_replace(["\r\n", "\r"], "\n", $message));

        return hash('sha256', $normalized);
    }

    private function groupSuggestionCounts($baseQuery, string $column): array
    {
        return (clone $baseQuery)
            ->select($column, DB::raw('COUNT(*) as total'))
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($item) => [
                $column => (string) ($item->{$column} ?? ''),
                'total' => (int) ($item->total ?? 0),
            ])
            ->values()
            ->all();
    }

    private function groupRequestCounts($baseQuery, string $column): array
    {
        return (clone $baseQuery)
            ->select($column, DB::raw('COUNT(*) as total'))
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupBy($column)
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($item) => [
                $column => (string) ($item->{$column} ?? ''),
                'total' => (int) ($item->total ?? 0),
            ])
            ->values()
            ->all();
    }
}
