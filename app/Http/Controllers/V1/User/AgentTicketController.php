<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\AgentProfile;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AgentCenterService;
use App\Services\TicketService;
use Illuminate\Http\Request;

class AgentTicketController extends Controller
{
    public function index(Request $request)
    {
        $agent = $request->user();
        $this->assertActiveAgent($agent);

        $current = max(1, (int) ($request->input('current') ?: $request->input('page') ?: 1));
        $pageSize = min(50, max(1, (int) ($request->input('page_size') ?: $request->input('per_page') ?: 10)));

        $query = Ticket::query()
            ->with(['user:id,email,plan_id,expired_at,transfer_enable,u,d', 'agentDomain:id,domain'])
            ->where('agent_user_id', (int) $agent->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        $total = (int) $query->count();
        $items = $query
            ->skip(($current - 1) * $pageSize)
            ->take($pageSize)
            ->get()
            ->map(fn (Ticket $ticket): array => $this->ticketPayload($ticket, $agent, false))
            ->values()
            ->all();

        return $this->success([
            'items' => $items,
            'total' => $total,
            'current_page' => $current,
            'per_page' => $pageSize,
            'last_page' => max(1, (int) ceil($total / $pageSize)),
        ]);
    }

    public function show(Request $request, int $id)
    {
        $agent = $request->user();
        $this->assertActiveAgent($agent);

        $ticket = $this->ownedTicket($agent, $id, true);
        if (!$ticket) {
            return $this->fail([400, __('Ticket does not exist')]);
        }

        return $this->success($this->ticketPayload($ticket, $agent, true));
    }

    public function reply(Request $request, int $id)
    {
        $agent = $request->user();
        $this->assertActiveAgent($agent);

        $ticket = $this->ownedTicket($agent, $id, false);
        if (!$ticket) {
            return $this->fail([400, __('Ticket does not exist')]);
        }
        if ((int) $ticket->status === Ticket::STATUS_CLOSED) {
            return $this->fail([400, __('The ticket is closed and cannot be replied')]);
        }

        $images = $request->file('images');
        $images = is_array($images) ? $images : ($images ? [$images] : []);
        if (trim((string) $request->input('message', '')) === '' && count($images) === 0) {
            return $this->fail([422, __('Message cannot be empty')]);
        }
        app(TicketService::class)->replyByAdmin(
            (int) $ticket->id,
            (string) $request->input('message', ''),
            (int) $agent->id,
            $images
        );

        $ticket = $this->ownedTicket($agent, $id, true);

        return $this->success($this->ticketPayload($ticket, $agent, true));
    }

    public function close(Request $request, int $id)
    {
        $agent = $request->user();
        $this->assertActiveAgent($agent);

        $ticket = $this->ownedTicket($agent, $id, false);
        if (!$ticket) {
            return $this->fail([400, __('Ticket does not exist')]);
        }

        $ticket->status = Ticket::STATUS_CLOSED;
        if (!$ticket->save()) {
            return $this->fail([500, __('Close failed')]);
        }

        return $this->success(true);
    }

    private function assertActiveAgent(?User $agent): void
    {
        if (!$agent) {
            abort(403);
        }

        $active = AgentProfile::query()
            ->where('user_id', (int) $agent->id)
            ->where('status', AgentCenterService::STATUS_ACTIVE)
            ->exists();

        if (!$active) {
            abort(403, 'Agent permission is not active');
        }
    }

    private function ownedTicket(User $agent, int $id, bool $withMessages): ?Ticket
    {
        $query = Ticket::query()
            ->with(['user:id,email,plan_id,expired_at,transfer_enable,u,d', 'agentDomain:id,domain'])
            ->where('agent_user_id', (int) $agent->id)
            ->where('id', $id);

        if ($withMessages) {
            $query->with(['messages' => fn ($messageQuery) => $messageQuery
                ->with('attachments')
                ->orderBy('id')]);
        }

        return $query->first();
    }

    private function ticketPayload(Ticket $ticket, User $agent, bool $withMessages): array
    {
        $payload = [
            'id' => (int) $ticket->id,
            'user_id' => (int) $ticket->user_id,
            'agent_user_id' => $ticket->agent_user_id !== null ? (int) $ticket->agent_user_id : null,
            'agent_domain_id' => $ticket->agent_domain_id !== null ? (int) $ticket->agent_domain_id : null,
            'subject' => (string) $ticket->subject,
            'level' => (int) $ticket->level,
            'status' => (int) $ticket->status,
            'reply_status' => $ticket->reply_status !== null ? (int) $ticket->reply_status : null,
            'user' => $ticket->user ? [
                'id' => (int) $ticket->user->id,
                'email' => (string) $ticket->user->email,
                'plan_id' => $ticket->user->plan_id !== null ? (int) $ticket->user->plan_id : null,
                'expired_at' => $ticket->user->expired_at !== null ? (int) $ticket->user->expired_at : null,
                'transfer_enable' => (int) ($ticket->user->transfer_enable ?? 0),
                'u' => (int) ($ticket->user->u ?? 0),
                'd' => (int) ($ticket->user->d ?? 0),
            ] : null,
            'agent_domain' => $ticket->agentDomain ? [
                'id' => (int) $ticket->agentDomain->id,
                'domain' => (string) $ticket->agentDomain->domain,
            ] : null,
            'created_at' => (int) $ticket->created_at,
            'updated_at' => (int) $ticket->updated_at,
        ];

        if ($withMessages) {
            $payload['messages'] = $ticket->messages
                ->map(fn ($message): array => [
                    'id' => (int) $message->id,
                    'ticket_id' => (int) $message->ticket_id,
                    'user_id' => $message->user_id !== null ? (int) $message->user_id : null,
                    'is_me' => (int) $message->user_id === (int) $agent->id,
                    'is_customer' => (int) $message->user_id === (int) $ticket->user_id,
                    'is_auto_reply' => (bool) ($message->is_auto_reply ?? false),
                    'auto_reply_rule' => $message->auto_reply_rule ?? null,
                    'message' => (string) $message->message,
                    'attachments' => $message->attachments
                        ->map(fn ($attachment): array => [
                            'id' => (int) $attachment->id,
                            'mime' => $attachment->mime,
                            'size' => $attachment->size !== null ? (int) $attachment->size : null,
                            'width' => $attachment->width !== null ? (int) $attachment->width : null,
                            'height' => $attachment->height !== null ? (int) $attachment->height : null,
                            'created_at' => $attachment->created_at !== null ? (int) $attachment->created_at : null,
                        ])
                        ->values()
                        ->all(),
                    'created_at' => (int) $message->created_at,
                    'updated_at' => (int) $message->updated_at,
                ])
                ->values()
                ->all();
        }

        return $payload;
    }
}
