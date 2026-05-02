<?php

namespace App\Services;

use App\Models\Knowledge;
use App\Models\Ticket;
use App\Models\TicketAiSuggestion;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    public function suggest(Ticket $ticket, ?string $instruction = null, ?int $adminId = null): array
    {
        $settings = $this->settings();
        if (!$settings['enabled']) {
            throw new RuntimeException('AI 工单助手未启用');
        }

        $apiKey = $this->apiKey();
        if ($apiKey === '') {
            throw new RuntimeException('AI API Key 未配置');
        }
        if ($settings['base_url'] === '' || $settings['model'] === '') {
            throw new RuntimeException('AI 接口地址或模型未配置');
        }

        $ticket->loadMissing(['messages', 'user']);
        $knowledge = $settings['knowledge_enable'] ? $this->findRelevantKnowledge($ticket) : [];
        $messages = $this->buildMessages($ticket, $knowledge, $settings, $instruction);

        $response = Http::timeout(30)
            ->acceptJson()
            ->withToken($apiKey)
            ->post(rtrim($settings['base_url'], '/') . '/chat/completions', [
                'model' => $settings['model'],
                'temperature' => $settings['temperature'],
                'messages' => $messages,
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('AI 服务请求失败：HTTP ' . $response->status());
        }

        $content = trim((string) data_get($response->json(), 'choices.0.message.content', ''));
        if ($content === '') {
            throw new RuntimeException('AI 服务未返回可用内容');
        }

        $result = $this->normalizeAiResult($content, $knowledge);
        $suggestion = TicketAiSuggestion::create([
            'ticket_id' => (int) $ticket->id,
            'admin_id' => $adminId,
            'model' => $settings['model'],
            'category' => $result['category'],
            'sentiment' => $result['sentiment'],
            'risk' => $result['risk'],
            'needs_human' => $result['needs_human'],
            'confidence' => $result['confidence'],
            'summary' => $result['summary'],
            'draft' => $result['draft'],
            'draft_hash' => $this->messageHash($result['draft']),
            'instruction' => trim((string) $instruction),
            'knowledge_refs' => $result['knowledge_refs'],
            'matched_knowledge' => $result['matched_knowledge'],
            'status' => TicketAiSuggestion::STATUS_GENERATED,
        ]);

        $result['suggestion_id'] = (int) $suggestion->id;
        $result['category_options'] = self::CATEGORY_OPTIONS;

        return $result;
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
     * @return array<int, array{role:string,content:string}>
     */
    private function buildMessages(Ticket $ticket, array $knowledge, array $settings, ?string $instruction): array
    {
        $conversation = $ticket->messages
            ->sortBy('created_at')
            ->take(-$settings['max_messages'])
            ->map(function ($message) use ($ticket) {
                $role = (int) $message->user_id === (int) $ticket->user_id ? '用户' : '客服';
                return sprintf('[%s] %s', $role, trim((string) $message->message));
            })
            ->implode("\n");

        $knowledgeText = collect($knowledge)
            ->map(fn (array $item) => sprintf('#%d %s / %s：%s', $item['id'], $item['category'], $item['title'], $item['body']))
            ->implode("\n\n");

        $user = $ticket->user;
        $context = [
            'ticket_id' => (int) $ticket->id,
            'subject' => (string) $ticket->subject,
            'level' => $ticket->level,
            'status' => $ticket->status,
            'reply_status' => $ticket->reply_status,
            'user_email' => $user?->email,
            'user_plan_id' => $user?->plan_id,
            'user_banned' => (bool) ($user?->banned ?? false),
        ];

        $prompt = "工单上下文：\n" . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n\n最近对话：\n" . ($conversation !== '' ? $conversation : '暂无对话')
            . "\n\n相关知识库：\n" . ($knowledgeText !== '' ? $knowledgeText : '无匹配知识库')
            . "\n\n固定分类：\n" . implode('、', self::CATEGORY_OPTIONS)
            . "\n\n管理员补充要求：\n" . (trim((string) $instruction) !== '' ? trim((string) $instruction) : '无')
            . "\n\n输出 JSON 字段要求：summary/category/sentiment/risk/needs_human/confidence/draft/knowledge_refs。category 必须使用固定分类，risk 只能是 low、medium、high，draft 是可以直接发给用户的中文回复草稿。";

        return [
            ['role' => 'system', 'content' => $settings['system_prompt'] ?: $this->defaultSystemPrompt()],
            ['role' => 'user', 'content' => $prompt],
        ];
    }

    /**
     * @param array<int, array{id:int,title:string,category:string,body:string}> $knowledge
     */
    private function normalizeAiResult(string $content, array $knowledge): array
    {
        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            $decoded = ['draft' => $content];
        }

        $category = $this->normalizeCategory((string) ($decoded['category'] ?? ''));
        $risk = $this->normalizeRisk((string) ($decoded['risk'] ?? ''));
        $needsHuman = (bool) ($decoded['needs_human'] ?? false)
            || $risk === 'high'
            || in_array($category, self::HUMAN_REVIEW_CATEGORIES, true);

        return [
            'summary' => trim((string) ($decoded['summary'] ?? '')),
            'category' => $category,
            'sentiment' => trim((string) ($decoded['sentiment'] ?? '')),
            'risk' => $risk,
            'needs_human' => $needsHuman,
            'confidence' => max(0, min(1, (float) ($decoded['confidence'] ?? 0))),
            'draft' => trim((string) ($decoded['draft'] ?? $content)),
            'knowledge_refs' => is_array($decoded['knowledge_refs'] ?? null) ? array_values($decoded['knowledge_refs']) : [],
            'matched_knowledge' => array_map(fn (array $item) => [
                'id' => $item['id'],
                'title' => $item['title'],
                'category' => $item['category'],
            ], $knowledge),
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
}
