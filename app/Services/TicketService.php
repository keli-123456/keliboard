<?php
namespace App\Services;


use App\Exceptions\ApiException;
use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\TicketMessageAttachment;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Services\Plugin\HookManager;
use Illuminate\Support\Facades\Storage;

class TicketService
{
    public function reply($ticket, $message, $userId, array $images = [])
    {
        $stored = [];
        try {
            DB::beginTransaction();
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);

            if (!empty($images)) {
                $attachmentService = new TicketAttachmentService();
                $stored = $attachmentService->storeUploadedImages($images, (int) $ticket->id, (int) $ticketMessage->id);
                foreach ($stored as $meta) {
                    $ok = TicketMessageAttachment::create([
                        'ticket_id' => $ticket->id,
                        'ticket_message_id' => $ticketMessage->id,
                        'user_id' => $userId,
                        'disk' => $meta['disk'],
                        'path' => $meta['path'],
                        'mime' => $meta['mime'],
                        'size' => $meta['size'],
                        'width' => $meta['width'],
                        'height' => $meta['height'],
                    ]);
                    if (!$ok) {
                        throw new \Exception();
                    }
                }
            }

            if ($userId !== $ticket->user_id) {
                $ticket->reply_status = Ticket::STATUS_OPENING;
            } else {
                $ticket->reply_status = Ticket::STATUS_CLOSED;
            }
            if (!$ticketMessage || !$ticket->save()) {
                throw new \Exception();
            }
            DB::commit();
            return $ticketMessage;
        } catch (\Exception $e) {
            DB::rollback();
            foreach ($stored as $meta) {
                try {
                    Storage::disk($meta['disk'])->delete($meta['path']);
                } catch (\Exception) {
                }
            }
            return false;
        }
    }

    public function replyByAdmin($ticketId, $message, $userId, array $images = []): void
    {
        $ticket = Ticket::where('id', $ticketId)
            ->first();
        if (!$ticket) {
            throw new ApiException('工单不存在');
        }
        $ticket->status = Ticket::STATUS_OPENING;
        $stored = [];
        try {
            DB::beginTransaction();
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);

            if (!empty($images)) {
                $attachmentService = new TicketAttachmentService();
                $stored = $attachmentService->storeUploadedImages($images, (int) $ticket->id, (int) $ticketMessage->id);
                foreach ($stored as $meta) {
                    $ok = TicketMessageAttachment::create([
                        'ticket_id' => $ticket->id,
                        'ticket_message_id' => $ticketMessage->id,
                        'user_id' => $userId,
                        'disk' => $meta['disk'],
                        'path' => $meta['path'],
                        'mime' => $meta['mime'],
                        'size' => $meta['size'],
                        'width' => $meta['width'],
                        'height' => $meta['height'],
                    ]);
                    if (!$ok) {
                        throw new ApiException('工单附件创建失败');
                    }
                }
            }

            if ($userId !== $ticket->user_id) {
                $ticket->reply_status = Ticket::STATUS_OPENING;
            } else {
                $ticket->reply_status = Ticket::STATUS_CLOSED;
            }
            if (!$ticketMessage || !$ticket->save()) {
                throw new ApiException('工单回复失败');
            }
            DB::commit();
            HookManager::call('ticket.reply.admin.after', [$ticket, $ticketMessage]);
        } catch (\Exception $e) {
            DB::rollBack();
            foreach ($stored as $meta) {
                try {
                    Storage::disk($meta['disk'])->delete($meta['path']);
                } catch (\Exception) {
                }
            }
            throw $e;
        }
        $this->sendEmailNotify($ticket, $ticketMessage);
    }

    public function createTicket($userId, $subject, $level, $message, array $images = [])
    {
        $stored = [];
        try {
            DB::beginTransaction();
            if (Ticket::where('status', 0)->where('user_id', $userId)->lockForUpdate()->first()) {
                DB::rollBack();
                throw new ApiException('存在未关闭的工单');
            }
            $ticket = Ticket::create([
                'user_id' => $userId,
                'subject' => $subject,
                'level' => $level
            ]);
            if (!$ticket) {
                throw new ApiException('工单创建失败');
            }
            $ticketMessage = TicketMessage::create([
                'user_id' => $userId,
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);
            if (!$ticketMessage) {
                DB::rollBack();
                throw new ApiException('工单消息创建失败');
            }

            if (!empty($images)) {
                $attachmentService = new TicketAttachmentService();
                $stored = $attachmentService->storeUploadedImages($images, (int) $ticket->id, (int) $ticketMessage->id);
                foreach ($stored as $meta) {
                    $ok = TicketMessageAttachment::create([
                        'ticket_id' => $ticket->id,
                        'ticket_message_id' => $ticketMessage->id,
                        'user_id' => $userId,
                        'disk' => $meta['disk'],
                        'path' => $meta['path'],
                        'mime' => $meta['mime'],
                        'size' => $meta['size'],
                        'width' => $meta['width'],
                        'height' => $meta['height'],
                    ]);
                    if (!$ok) {
                        throw new ApiException('工单附件创建失败');
                    }
                }
            }

            DB::commit();
            return $ticket;
        } catch (\Exception $e) {
            DB::rollBack();
            foreach ($stored as $meta) {
                try {
                    Storage::disk($meta['disk'])->delete($meta['path']);
                } catch (\Exception) {
                }
            }
            throw $e;
        }
    }

    // 半小时内不再重复通知
    private function sendEmailNotify(Ticket $ticket, TicketMessage $ticketMessage)
    {
        $user = User::find($ticket->user_id);
        $cacheKey = 'ticket_sendEmailNotify_' . $ticket->user_id;
        if (!Cache::get($cacheKey)) {
            Cache::put($cacheKey, 1, 1800);
            SendEmailJob::dispatch([
                'email' => $user->email,
                'subject' => '您在' . admin_setting('app_name', 'XBoard') . '的工单得到了回复',
                'template_name' => 'notify',
                'template_value' => [
                    'name' => admin_setting('app_name', 'XBoard'),
                    'url' => admin_setting('app_url'),
                    'content' => "主题：{$ticket->subject}\r\n回复内容：{$ticketMessage->message}"
                ]
            ]);
        }
    }
}
