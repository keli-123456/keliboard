<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserNotificationService
{
    public function available(): bool
    {
        return Schema::hasColumn('v2_ticket_message', 'user_read_at');
    }

    private function replies(int $userId): Builder
    {
        return DB::table('v2_ticket_message as m')
            ->join('v2_ticket as t', 't.id', '=', 'm.ticket_id')
            ->where('t.user_id', $userId)
            ->where(fn (Builder $query) => $query->whereColumn('m.user_id', '<>', 't.user_id')->orWhereNull('m.user_id'));
    }

    public function unreadIds(Ticket $ticket, ?array $messageIds = null): array
    {
        return $this->replies((int) $ticket->user_id)->where('t.id', $ticket->id)
            ->when($messageIds !== null, fn (Builder $query) => $query->whereIn('m.id', $messageIds))
            ->whereNull('m.user_read_at')->orderBy('m.id')->pluck('m.id')->map(fn ($id) => (int) $id)->all();
    }

    public function markRead(Ticket $ticket, array $ids): array
    {
        $confirmed = $this->replies((int) $ticket->user_id)->where('t.id', $ticket->id)
            ->whereIn('m.id', $ids)->pluck('m.id')->map(fn ($id) => (int) $id)->all();
        DB::table('v2_ticket_message')->where('ticket_id', $ticket->id)
            ->whereIn('id', $confirmed)->whereNull('user_read_at')->update(['user_read_at' => time()]);
        return $confirmed;
    }

    public function summary(User $user): array
    {
        return DB::transaction(fn () => $this->snapshot($user));
    }

    private function snapshot(User $user): array
    {
        $unread = $this->replies((int) $user->id)->whereNull('m.user_read_at');
        $total = (clone $unread)->count();
        $tickets = (clone $unread)->select('t.id', 't.subject')
            ->selectRaw('COUNT(*) as unread_count, MAX(m.id) as latest_message_id, MAX(m.created_at) as latest_message_at')
            ->groupBy('t.id', 't.subject')->orderByDesc('latest_message_id')->limit(20)->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id, 'subject' => (string) $row->subject,
                'unread_count' => (int) $row->unread_count, 'latest_message_id' => (int) $row->latest_message_id,
                'latest_message_at' => (int) $row->latest_message_at,
            ])->all();
        $pending = Order::query()->where('user_id', $user->id)->where('status', 0);
        $pendingCount = (clone $pending)->count();
        $order = $pending->orderByDesc('id')->first(['trade_no', 'total_amount']);
        $totalTraffic = max(0, (float) $user->transfer_enable);
        $used = max(0, (float) $user->u) + max(0, (float) $user->d);
        return [
            'version' => 1, 'unread_count' => $total, 'tickets' => $tickets,
            'pending_orders_count' => $pendingCount,
            'pending_order' => $order ? ['trade_no' => (string) $order->trade_no, 'total_amount' => (int) $order->total_amount] : null,
            'traffic_percent' => $totalTraffic > 0 ? (int) min(100, round($used / $totalTraffic * 100)) : 0,
            'plan_id' => (int) $user->plan_id,
        ];
    }
}
