<?php

namespace App\Http\Controllers\V2\Staff;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketMessageAttachment;
use App\Services\TicketService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

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

    private function applyFiltersAndSorts(Request $request, $builder): void
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
        }

        return $this->fetchTickets($request);
    }

    private function fetchTicketById(Request $request)
    {
        $ticket = Ticket::with(['messages.ticket', 'messages.attachments', 'user.plan:id,name'])->find($request->input('id'));
        if (!$ticket) {
            return $this->fail([400202, '工单不存在']);
        }

        $result = $ticket->toArray();
        if ($ticket->relationLoaded('user') && $ticket->user) {
            $result['user'] = UserController::transformUserData($ticket->user);
        }

        return $this->success($result);
    }

    private function fetchTickets(Request $request)
    {
        $ticketModel = Ticket::with('user:id,email')
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

        $items = collect($tickets->items())->map(function ($ticket) {
            $ticketData = $ticket->toArray();
            if ($ticket->relationLoaded('user') && $ticket->user) {
                $ticketData['user'] = [
                    'id' => (int) $ticket->user->id,
                    'email' => (string) $ticket->user->email,
                ];
            }
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

        $actorId = (int) (Auth::guard()->id() ?: ($request->input('user.id') ?? 0));
        if ($actorId <= 0) {
            throw new ApiException('未登录或登陆已过期', 403);
        }

        $images = $request->file('images');
        $images = is_array($images) ? $images : ($images ? [$images] : []);

        $ticketService = new TicketService();
        $ticketService->replyByAdmin(
            (int) $request->input('id'),
            (string) $request->input('message', ''),
            $actorId,
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
        } catch (\Exception) {
            return $this->fail([500101, '关闭失败']);
        }
    }

    public function attachment(int $id)
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

        $absolute = Storage::disk($disk)->path($path);
        $headers = [
            'Cache-Control' => 'private, max-age=3600',
        ];
        if ($attachment->mime) {
            $headers['Content-Type'] = $attachment->mime;
        }
        return response()->file($absolute, $headers);
    }
}
