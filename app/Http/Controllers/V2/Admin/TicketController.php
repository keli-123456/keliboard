<?php

namespace App\Http\Controllers\V2\Admin;

use App\Exceptions\TicketAiProviderException;
use App\Http\Controllers\Controller;
use App\Models\TicketMessageAttachment;
use App\Models\Ticket;
use App\Models\TicketAiSuggestion;
use App\Models\TicketMessage;
use App\Services\TicketAttachmentService;
use App\Services\TicketAiAssistantService;
use App\Services\TicketService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Exceptions\ApiException;
use Plugin\SubscriptionControl\Services\SubscriptionControlEventStore;

class TicketController extends Controller
{
    private const TICKET_FILTER_FIELDS = [
        'id' => 'id',
        'site_id' => 'site_id',
        'user_id' => 'user_id',
        'agent_user_id' => 'agent_user_id',
        'agent_domain_id' => 'agent_domain_id',
        'subject' => 'subject',
        'level' => 'level',
        'status' => 'status',
        'reply_status' => 'reply_status',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
    ];

    private const TICKET_SORT_FIELDS = [
        'id' => 'id',
        'site_id' => 'site_id',
        'user_id' => 'user_id',
        'agent_user_id' => 'agent_user_id',
        'agent_domain_id' => 'agent_domain_id',
        'subject' => 'subject',
        'level' => 'level',
        'status' => 'status',
        'reply_status' => 'reply_status',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
    ];

    private function applyFiltersAndSorts(Request $request, $builder)
    {
        $filters = $request->input('filter');
        if (is_array($filters)) {
            collect($filters)->each(function ($filter) use ($builder) {
                if (!is_array($filter) || !array_key_exists('id', $filter)) {
                    return;
                }

                $key = trim((string) $filter['id']);
                if (!$this->isAllowedTicketFilterField($key)) {
                    return;
                }

                $value = $filter['value'] ?? null;
                $builder->where(function ($query) use ($key, $value) {
                    if (in_array($key, ['keyword', 'q'], true)) {
                        $raw = is_string($value) || is_numeric($value) ? trim((string) $value) : '';
                        if ($raw === '') {
                            return;
                        }

                        $tokens = preg_split('/\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                        $tokens = array_values(array_filter(array_map(fn($t) => trim((string) $t), $tokens)));

                        foreach ($tokens as $token) {
                            $query->where(function ($sub) use ($token) {
                                $sub->where('subject', 'like', "%{$token}%")
                                    ->orWhereHas('user', function ($q) use ($token) {
                                        $q->where('email', 'like', "%{$token}%");
                                    });

                                if (is_numeric($token)) {
                                    $n = (int) $token;
                                    $sub->orWhere('id', $n)
                                        ->orWhere('user_id', $n);
                                }
                            });
                        }
                        return;
                    }

                    $column = $this->resolveTicketFilterField($key);
                    if ($column === null) {
                        return;
                    }

                    if (in_array($key, ['site_id', 'agent_user_id', 'agent_domain_id'], true)) {
                        if (is_array($value)) {
                            $ids = $this->normalizePositiveIntegerFilterValues($value);
                            if ($ids !== []) {
                                $query->whereIn($column, $ids);
                            }
                            return;
                        }

                        $id = $this->normalizePositiveIntegerFilterValue($value);
                        if ($id !== null) {
                            $query->where($column, $id);
                        }
                    } elseif (is_array($value)) {
                        $query->whereIn($column, $value);
                    } else {
                        $query->where($column, 'like', "%{$value}%");
                    }
                });
            });
        }

        $sorts = $request->input('sort');
        if (is_array($sorts)) {
            collect($sorts)->each(function ($sort) use ($builder) {
                if (!is_array($sort) || !array_key_exists('id', $sort)) {
                    return;
                }

                $key = $this->resolveTicketSortField(trim((string) $sort['id']));
                if ($key === null) {
                    return;
                }

                $value = !empty($sort['desc']) ? 'DESC' : 'ASC';
                $builder->orderBy($key, $value);
            });
        }
    }

    private function isAllowedTicketFilterField(string $field): bool
    {
        return in_array($field, ['keyword', 'q'], true)
            || isset(self::TICKET_FILTER_FIELDS[$field]);
    }

    private function resolveTicketFilterField(string $field): ?string
    {
        return self::TICKET_FILTER_FIELDS[$field] ?? null;
    }

    private function resolveTicketSortField(string $field): ?string
    {
        return self::TICKET_SORT_FIELDS[$field] ?? null;
    }

    private function normalizePositiveIntegerFilterValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value)) {
            $value = trim($value);
            if ($value !== '' && ctype_digit($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    private function normalizePositiveIntegerFilterValues(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            $id = $this->normalizePositiveIntegerFilterValue($value);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            return $this->fetchTicketById($request);
        } else {
            return $this->fetchTickets($request);
        }
    }

    /**
     * Summary of fetchTicketById
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    private function fetchTicketById(Request $request)
    {
        $ticket = Ticket::with([
            'messages.ticket',
            'messages.attachments',
            'user.site:id,code,name',
            'site:id,code,name',
            'agent:id,email',
            'agentDomain:id,domain',
        ])->find($request->input('id'));

        if (!$ticket) {
            return $this->fail([400202, '工单不存在']);
        }
        $result = $ticket->toArray();
        $result['user'] = UserController::transformUserData($ticket->user);
        $result['site'] = $this->formatSitePayload($this->resolveTicketSite($ticket));
        $result['agent'] = $this->formatAgentPayload($ticket->agent);
        $result['agent_domain'] = $this->formatAgentDomainPayload($ticket->agentDomain);
        $result['source'] = $this->buildTicketSourcePayload(
            $result['site'],
            $result['agent'],
            $result['agent_domain']
        );
        $result['risk_context'] = $this->buildSubscriptionRiskContext(
            (int) $ticket->user_id,
            (string) ($ticket->user->email ?? '')
        );

        return $this->success($result);
    }

    private function buildSubscriptionRiskContext(int $userId, string $email): array
    {
        $empty = [
            'available' => false,
            'risk_level' => 'none',
            'risk_score' => 0,
            'event_count' => 0,
            'reset_count' => 0,
            'last_trigger_at' => null,
            'client_ips' => [],
            'ua_categories' => [],
            'regions' => [],
            'ip_types' => [],
            'latest_events' => [],
        ];

        if ($userId <= 0) {
            return $empty;
        }

        try {
            $store = new SubscriptionControlEventStore();
            if (!$store->available()) {
                return $empty;
            }

            $events = $store->recent(100, trim($email));
        } catch (\Throwable) {
            return $empty;
        }

        $events = array_values(array_filter($events, function (array $event) use ($userId): bool {
            return (int) ($event['user_id'] ?? 0) === $userId;
        }));

        if (empty($events)) {
            return array_merge($empty, [
                'available' => true,
            ]);
        }

        $unique = function (array $values, int $limit = 6): array {
            $out = [];
            foreach ($values as $value) {
                if (is_array($value)) {
                    foreach ($value as $item) {
                        $item = trim((string) $item);
                        if ($item !== '' && !in_array($item, $out, true)) {
                            $out[] = $item;
                        }
                    }
                    continue;
                }

                $value = trim((string) ($value ?? ''));
                if ($value !== '' && !in_array($value, $out, true)) {
                    $out[] = $value;
                }
            }

            return array_slice($out, 0, $limit);
        };

        $maxScore = 0;
        foreach ($events as $event) {
            $maxScore = max($maxScore, (int) ($event['risk_score'] ?? 0));
        }

        $riskLevel = $maxScore >= 60 ? 'high' : ($maxScore >= 20 ? 'medium' : 'low');
        $resetCount = count(array_filter($events, fn(array $event): bool => in_array((string) ($event['action'] ?? ''), ['reset_token', 'reset_token_uuid'], true)));

        return [
            'available' => true,
            'risk_level' => $riskLevel,
            'risk_score' => $maxScore,
            'event_count' => count($events),
            'reset_count' => $resetCount,
            'last_trigger_at' => (int) ($events[0]['created_at'] ?? 0) ?: null,
            'client_ips' => $unique(array_column($events, 'client_ip')),
            'ua_categories' => $unique(array_merge(array_column($events, 'ua_category'), array_column($events, 'ua_categories'))),
            'regions' => $unique(array_merge(array_column($events, 'region'), array_column($events, 'regions'))),
            'ip_types' => $unique(array_column($events, 'ip_type')),
            'latest_events' => array_map(function (array $event): array {
                return [
                    'id' => (string) ($event['id'] ?? ''),
                    'code' => (string) ($event['code'] ?? ''),
                    'reason' => (string) ($event['reason'] ?? ''),
                    'action' => (string) ($event['action'] ?? ''),
                    'client_ip' => $event['client_ip'] ?? null,
                    'ua_category' => $event['ua_category'] ?? null,
                    'region' => $event['region'] ?? null,
                    'created_at' => $event['created_at'] ?? null,
                ];
            }, array_slice($events, 0, 5)),
        ];
    }

    /**
     * Summary of fetchTickets
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    private function fetchTickets(Request $request)
    {
        $ticketModel = Ticket::with(['user.site:id,code,name', 'site:id,code,name', 'agent:id,email', 'agentDomain:id,domain'])
            ->when($request->has('status'), function ($query) use ($request) {
                $status = $request->input('status');
                if (is_scalar($status) && $status !== '') {
                    $query->where('status', (int) $status);
                }
            })
            ->when($request->has('reply_status'), function ($query) use ($request) {
                $replyStatus = $request->input('reply_status');
                if (is_array($replyStatus)) {
                    $replyStatus = array_values(array_filter(
                        array_map(fn ($value) => is_scalar($value) && $value !== '' ? (int) $value : null, $replyStatus),
                        fn ($value) => $value !== null
                    ));
                    if ($replyStatus !== []) {
                        $query->whereIn('reply_status', $replyStatus);
                    }
                } elseif ($replyStatus !== null && $replyStatus !== '') {
                    $query->where('reply_status', (int) $replyStatus);
                }
            })
            ->when($request->has('email'), function ($query) use ($request) {
                $query->whereHas('user', function ($q) use ($request) {
                    $q->where('email', $request->input('email'));
                });
            })
            ->when($request->filled('ai_category') && $request->input('ai_category') !== 'all' && $this->hasTicketAiSuggestionTable(), function ($query) use ($request) {
                $category = trim((string) $request->input('ai_category'));
                $query->whereExists(function ($sub) use ($category) {
                    $sub->selectRaw('1')
                        ->from('v2_ticket_ai_suggestion')
                        ->whereColumn('v2_ticket_ai_suggestion.ticket_id', 'v2_ticket.id')
                        ->where('v2_ticket_ai_suggestion.category', $category);
                });
            })
            ->when($request->boolean('ai_needs_human') && $this->hasTicketAiSuggestionTable(), function ($query) {
                $query->whereExists(function ($sub) {
                    $sub->selectRaw('1')
                        ->from('v2_ticket_ai_suggestion')
                        ->whereColumn('v2_ticket_ai_suggestion.ticket_id', 'v2_ticket.id')
                        ->where('v2_ticket_ai_suggestion.needs_human', 1);
                });
            });

        $this->applyFiltersAndSorts($request, $ticketModel);
        $tickets = $ticketModel
            ->latest('updated_at')
            ->paginate(
                perPage: $request->integer('pageSize', 10),
                page: $request->integer('current', 1)
            );

        $ticketIds = collect($tickets->items())->pluck('id')->map(fn ($id) => (int) $id)->all();
        $latestAiByTicket = collect();
        if ($ticketIds !== [] && $this->hasTicketAiSuggestionTable()) {
            $latestAiByTicket = TicketAiSuggestion::query()
                ->whereIn('ticket_id', $ticketIds)
                ->orderByDesc('id')
                ->get()
                ->unique('ticket_id')
                ->keyBy('ticket_id');
        }

        // 获取items然后映射转换
        $items = collect($tickets->items())->map(function ($ticket) use ($latestAiByTicket) {
            $ticketData = $ticket->toArray();
            $ticketData['user'] = UserController::transformUserData($ticket->user);
            $ticketData['site'] = $this->formatSitePayload($this->resolveTicketSite($ticket));
            $ticketData['agent'] = $this->formatAgentPayload($ticket->agent);
            $ticketData['agent_domain'] = $this->formatAgentDomainPayload($ticket->agentDomain);
            $ticketData['source'] = $this->buildTicketSourcePayload(
                $ticketData['site'],
                $ticketData['agent'],
                $ticketData['agent_domain']
            );
            $latestAi = $latestAiByTicket->get((int) $ticket->id);
            if ($latestAi) {
                $ticketData['ai_category'] = $latestAi->category;
                $ticketData['ai_risk'] = $latestAi->risk;
                $ticketData['ai_needs_human'] = (bool) $latestAi->needs_human;
            }
            return $ticketData;
        })->all();

        return $this->paginate($tickets, $items);
    }

    private function resolveTicketSite(Ticket $ticket)
    {
        if ($ticket->relationLoaded('site') && $ticket->site) {
            return $ticket->site;
        }

        if (
            $ticket->relationLoaded('user')
            && $ticket->user
            && $ticket->user->relationLoaded('site')
            && $ticket->user->site
        ) {
            return $ticket->user->site;
        }

        return null;
    }

    private function hasTicketAiSuggestionTable(): bool
    {
        try {
            return app('db')->connection()->getSchemaBuilder()->hasTable('v2_ticket_ai_suggestion');
        } catch (\Throwable) {
            return false;
        }
    }

    private function formatAgentPayload($agent): ?array
    {
        if (!$agent) {
            return null;
        }

        return [
            'id' => (int) $agent->id,
            'email' => (string) $agent->email,
        ];
    }

    private function formatAgentDomainPayload($domain): ?array
    {
        if (!$domain) {
            return null;
        }

        return [
            'id' => (int) $domain->id,
            'domain' => (string) $domain->domain,
        ];
    }

    private function formatSitePayload($site): ?array
    {
        if (!$site) {
            return null;
        }

        return [
            'id' => (int) $site->id,
            'code' => (string) $site->code,
            'name' => (string) $site->name,
        ];
    }

    private function buildTicketSourcePayload(?array $site, ?array $agent, ?array $agentDomain): array
    {
        if ($agent || $agentDomain) {
            $label = $agentDomain
                ? '代理域名 ' . (string) $agentDomain['domain']
                : '代理账号 ' . (string) ($agent['email'] ?? ('#' . ($agent['id'] ?? '')));

            return [
                'type' => 'agent',
                'label' => $label,
                'site' => $site,
                'agent' => $agent,
                'agent_domain' => $agentDomain,
            ];
        }

        if ($site) {
            return [
                'type' => 'site',
                'label' => '站点 ' . (string) $site['name'],
                'site' => $site,
                'agent' => null,
                'agent_domain' => null,
            ];
        }

        return [
            'type' => 'platform',
            'label' => '主站',
            'site' => null,
            'agent' => null,
            'agent_domain' => null,
        ];
    }

    public function reply(Request $request)
    {
        $maxImages = (int) config('tickets.attachments.max_images', 3);
        $maxKb = (int) config('tickets.attachments.max_kb', 5120);
        $request->validate([
            'id' => 'required|numeric',
            'message' => 'required_without:images|string',
            'ai_suggestion_id' => 'nullable|integer',
            'images' => 'nullable|array|max:' . $maxImages,
            'images.*' => 'file|image|mimes:jpg,jpeg,png,webp|max:' . $maxKb
        ], [
            'id.required' => '工单ID不能为空',
            'message.required_without' => '消息不能为空'
        ]);
        $images = $request->file('images');
        $images = is_array($images) ? $images : ($images ? [$images] : []);
        $ticketService = new TicketService();
        $ticketService->replyByAdmin(
            $request->input('id'),
            (string) $request->input('message', ''),
            $request->user()->id,
            $images,
            [
                'ai_suggestion_id' => $request->filled('ai_suggestion_id')
                    ? (int) $request->input('ai_suggestion_id')
                    : null,
            ]
        );
        return $this->success(true);
    }

    public function close(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric'
        ], [
            'id.required' => '工单ID不能为空'
        ]);
        try {
            $ticket = Ticket::findOrFail($request->input('id'));
            $ticket->status = Ticket::STATUS_CLOSED;
            $ticket->save();
            return $this->success(true);
        } catch (ModelNotFoundException $e) {
            return $this->fail([400202, '工单不存在']);
        } catch (\Exception $e) {
            return $this->fail([500101, '关闭失败']);
        }
    }

    public function show($ticketId)
    {
        $ticket = Ticket::with([
            'user',
            'messages' => function ($query) {
                $query->with(['user']); // 如果需要用户信息
            }
        ])->findOrFail($ticketId);

        // 自动包含 is_me 属性
        return response()->json([
            'data' => $ticket
        ]);
    }

    public function autoReplyStats(Request $request)
    {
        $days = max(1, min(90, (int) $request->input('days', 7)));
        $since = time() - ($days * 86400);

        $baseQuery = TicketMessage::query()
            ->where('is_auto_reply', 1)
            ->where('created_at', '>=', $since);

        $autoReplyTotal = (clone $baseQuery)->count();
        $autoReplyTicketTotal = (clone $baseQuery)
            ->distinct('ticket_id')
            ->count('ticket_id');

        $topRules = (clone $baseQuery)
            ->select('auto_reply_rule', DB::raw('COUNT(*) as total'))
            ->groupBy('auto_reply_rule')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(function ($item) {
                $label = trim((string) ($item->auto_reply_rule ?? ''));
                return [
                    'rule' => $label !== '' ? $label : 'default',
                    'total' => (int) ($item->total ?? 0),
                ];
            })
            ->values();

        $pendingAutoReplied = Ticket::query()
            ->where('status', Ticket::STATUS_OPENING)
            ->where('reply_status', Ticket::REPLY_STATUS_AUTO_REPLIED)
            ->count();

        $waitingAdmin = Ticket::query()
            ->where('status', Ticket::STATUS_OPENING)
            ->where('reply_status', Ticket::REPLY_STATUS_WAITING_ADMIN)
            ->count();

        return $this->success([
            'days' => $days,
            'since' => $since,
            'auto_reply_total' => (int) $autoReplyTotal,
            'auto_reply_ticket_total' => (int) $autoReplyTicketTotal,
            'pending_auto_replied' => (int) $pendingAutoReplied,
            'waiting_admin' => (int) $waitingAdmin,
            'top_rules' => $topRules,
        ]);
    }

    public function aiCapabilities(Request $request, TicketAiAssistantService $assistant)
    {
        return $this->success($assistant->capabilities());
    }

    public function aiTestConnection(Request $request, TicketAiAssistantService $assistant)
    {
        try {
            return $this->success($assistant->testConnection($request->user()?->id));
        } catch (TicketAiProviderException $exception) {
            return $this->fail([422, $exception->errorCode()]);
        } catch (\RuntimeException $exception) {
            return $this->fail([422, $exception->getMessage()]);
        }
    }

    public function aiSuggest(Request $request, TicketAiAssistantService $assistant)
    {
        $params = $request->validate([
            'id' => 'required|integer',
            'instruction' => 'nullable|string|max:1000',
        ], [
            'id.required' => '工单ID不能为空',
        ]);

        $ticket = Ticket::with(['messages', 'user'])->find((int) $params['id']);
        if (!$ticket) {
            return $this->fail([400202, '工单不存在']);
        }

        try {
            return $this->success($assistant->suggest($ticket, $params['instruction'] ?? null, $request->user()?->id));
        } catch (TicketAiProviderException $e) {
            return $this->fail([422, $e->errorCode()]);
        } catch (\RuntimeException $e) {
            return $this->fail([422, $e->getMessage()]);
        }
    }

    public function aiSuggestionFeedback(Request $request, TicketAiAssistantService $assistant)
    {
        $params = $request->validate([
            'id' => 'required|integer',
            'ticket_id' => 'required|integer',
            'status' => 'required|in:inserted,discarded',
        ]);

        try {
            return $this->success($assistant->recordFeedback(
                (int) $params['id'],
                (int) $params['ticket_id'],
                $request->user()?->id,
                (string) $params['status']
            ));
        } catch (\RuntimeException $e) {
            return $this->fail([422, $e->getMessage()]);
        }
    }

    public function aiStats(Request $request, TicketAiAssistantService $assistant)
    {
        return $this->success($assistant->stats((int) $request->input('days', 7)));
    }

    public function attachment(int $id)
    {
        return $this->serveAttachmentFile($id, false);
    }

    public function preview(Request $request, int $id)
    {
        // Public preview URLs intentionally live outside secure_path and rely on
        // temporarySignedRoute(). Keep this contract in sync with frontend image
        // tags; token headers are not expected on thumbnail/preview requests.
        if (!$request->hasValidSignature() && !$request->hasValidSignature(false)) {
            throw new ApiException('Forbidden', 403);
        }

        return $this->serveAttachmentFile($id, true, (string) $request->query('variant', ''));
    }

    private function serveAttachmentFile(int $id, bool $publicPreview, string $variant = '')
    {
        $attachment = TicketMessageAttachment::find($id);
        if (!$attachment) {
            throw new ApiException('Not Found', 404);
        }

        $disk = $attachment->disk ?: (string) config('tickets.attachments.disk', 'local');
        $path = $attachment->path;
        if (!$path || !Storage::disk($disk)->exists($path)) {
            throw new ApiException('Not Found', 404);
        }

        $resolvedPath = $path;
        $resolvedMime = (string) ($attachment->mime ?: 'application/octet-stream');
        $cacheControl = $publicPreview ? 'public, max-age=600' : 'private, max-age=3600';

        if ($publicPreview && $variant === 'thumb') {
            $thumbnail = app(TicketAttachmentService::class)->ensureThumbnail($disk, $path, $attachment->mime);
            if ($thumbnail && !empty($thumbnail['path']) && Storage::disk($disk)->exists($thumbnail['path'])) {
                $resolvedPath = (string) $thumbnail['path'];
                $resolvedMime = (string) ($thumbnail['mime'] ?: 'image/webp');
                $cacheControl = 'public, max-age=86400, stale-while-revalidate=604800';
            }
        }

        $absolute = Storage::disk($disk)->path($resolvedPath);
        $headers = [
            'Cache-Control' => $cacheControl,
            'Content-Disposition' => 'inline',
        ];
        if ($resolvedMime) {
            $headers['Content-Type'] = $resolvedMime;
        }
        return response()->file($absolute, $headers);
    }
}
