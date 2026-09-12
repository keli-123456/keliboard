<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\UserNotificationService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function fetch(Request $request, UserNotificationService $notifications)
    {
        if (!$notifications->available()) {
            return $this->fail([503, 'Notification read tracking requires database migration']);
        }
        return $this->success($notifications->summary($request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, UserNotificationService $notifications)
    {
        $data = $request->validate([
            'id' => 'required|integer|min:1',
            'message_ids' => 'required|array|min:1|max:100',
            'message_ids.*' => 'required|integer|min:1|distinct',
        ]);
        $ticket = Ticket::query()->where('user_id', $request->user()->id)->find($data['id']);
        if (!$ticket) {
            return $this->fail([404, __('Ticket does not exist')]);
        }
        if (!$notifications->available()) {
            return $this->fail([503, 'Notification read tracking requires database migration']);
        }
        return $this->success(['read_ids' => $notifications->markRead($ticket, $data['message_ids'])])
            ->header('Cache-Control', 'private, no-store');
    }
}
