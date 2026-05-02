<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\TicketMessageAttachment;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\TicketAttachmentService;
use App\Services\TicketAiAssistantService;
use App\Services\TicketService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Exceptions\ApiException;

class TicketController extends Controller
{
    private const TICKET_FILTER_FIELDS = [
        'id' => 'id',
        'user_id' => 'user_id',
        'subject' => 'subject',
        'level' => 'level',
        'status' => 'status',
        'reply_status' => 'reply_status',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
    ];

    private const TICKET_SORT_FIELDS = [
        'id' => 'id',
        'user_id' => 'user_id',
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

                    if (is_array($value)) {
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
        $ticket = Ticket::with(['messages.ticket', 'messages.attachments', 'user'])->find($request->input('id'));

        if (!$ticket) {
            return $this->fail([400202, '工单不存在']);
        }
        $result = $ticket->toArray();
        $result['user'] = UserController::transformUserData($ticket->user);

        return $this->success($result);
    }

    /**
     * Summary of fetchTickets
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    private function fetchTickets(Request $request)
    {
        $ticketModel = Ticket::with('user')
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
            });

        $this->applyFiltersAndSorts($request, $ticketModel);
        $tickets = $ticketModel
            ->latest('updated_at')
            ->paginate(
                perPage: $request->integer('pageSize', 10),
                page: $request->integer('current', 1)
            );

        // 获取items然后映射转换
        $items = collect($tickets->items())->map(function ($ticket) {
            $ticketData = $ticket->toArray();
            $ticketData['user'] = UserController::transformUserData($ticket->user);
            return $ticketData;
        })->all();

        return $this->paginate($tickets, $items);
    }

    public function reply(Request $request)
    {
        $maxImages = (int) config('tickets.attachments.max_images', 3);
        $maxKb = (int) config('tickets.attachments.max_kb', 5120);
        $request->validate([
            'id' => 'required|numeric',
            'message' => 'required_without:images|string',
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
            $images
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
            return $this->success($assistant->suggest($ticket, $params['instruction'] ?? null));
        } catch (\RuntimeException $e) {
            return $this->fail([422, $e->getMessage()]);
        }
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
        if (!$request->hasValidSignature()) {
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
