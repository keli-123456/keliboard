<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Lang;

class CheckTicket extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:ticket';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '工单检查任务';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle(): int
    {
        $now = time();

        $this->closeWaitingUserTickets($now);
        $this->closeWaitingAdminTickets($now);

        return self::SUCCESS;
    }

    private function closeWaitingUserTickets(int $now): void
    {
        $hours = max(0, (int) config('tickets.auto_close.waiting_user_hours', 24));
        if ($hours === 0) {
            return;
        }

        Ticket::query()
            ->where('status', Ticket::STATUS_OPENING)
            ->where('updated_at', '<=', $now - ($hours * 3600))
            ->where('reply_status', Ticket::REPLY_STATUS_WAITING_USER)
            ->chunkById(100, function ($tickets) {
                foreach ($tickets as $ticket) {
                    if ((int) $ticket->user_id === (int) ($ticket->last_reply_user_id ?? 0)) {
                        continue;
                    }

                    $ticket->status = Ticket::STATUS_CLOSED;
                    $ticket->save();
                }
            });
    }

    private function closeWaitingAdminTickets(int $now): void
    {
        $hours = max(0, (int) config('tickets.auto_close.waiting_admin_hours', 24));
        if ($hours === 0) {
            return;
        }

        $withdrawSubjects = $this->getWithdrawTicketSubjects();

        Ticket::query()
            ->where('status', Ticket::STATUS_OPENING)
            ->where('updated_at', '<=', $now - ($hours * 3600))
            ->whereIn('reply_status', [
                Ticket::REPLY_STATUS_WAITING_ADMIN,
                Ticket::REPLY_STATUS_AUTO_REPLIED,
            ])
            ->when(
                !empty($withdrawSubjects),
                fn($query) => $query->whereNotIn('subject', $withdrawSubjects)
            )
            ->chunkById(100, function ($tickets) {
                foreach ($tickets as $ticket) {
                    $ticket->status = Ticket::STATUS_CLOSED;
                    $ticket->save();
                }
            });
    }

    private function getWithdrawTicketSubjects(): array
    {
        $key = '[Commission Withdrawal Request] This ticket is opened by the system';

        return array_values(array_unique(array_filter([
            Lang::get($key, [], 'zh-CN'),
            Lang::get($key, [], 'zh-TW'),
            Lang::get($key, [], 'en-US'),
            $key,
        ], fn($subject) => is_string($subject) && trim($subject) !== '')));
    }
}
