<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Exceptions\ApiException;
use App\Models\GiftCardCode;
use App\Models\User;
use App\Services\GiftCardService;
use App\Services\UserService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Tests\Support\GiftCardMysqlFixture;

$emit = static function (array $packet): void {
    echo json_encode($packet, JSON_THROW_ON_ERROR) . "\n";
    flush();
};
try {
    if (PHP_OS_FAMILY !== 'Linux' || count($argv) !== 6 || !ctype_digit($argv[2]) || !ctype_digit($argv[3])) {
        throw new RuntimeException('Invalid acceptance worker arguments.');
    }
    [, $owner, $codeId, $userId, $mode, $prevalidate] = $argv;
    if (!in_array($mode, ['normal', 'fail_award'], true) || !in_array($prevalidate, ['0', '1'], true)) {
        throw new RuntimeException('Invalid acceptance worker mode.');
    }
    $fixture = new GiftCardMysqlFixture();
    $fixture->assertOwner($owner);
    $service = (new GiftCardService(GiftCardCode::findOrFail((int) $codeId)->code))->setUser(User::findOrFail((int) $userId));
    if ($prevalidate === '1') {
        $service->validate();
    }
    $failure = null;
    if ($mode === 'fail_award') {
        $failure = new class extends UserService {
            public bool $debitSeen = false;

            public function addBalance(int $userId, int $balance): bool
            {
                $this->debitSeen = \App\Models\AgentLedger::query()->where('target_user_id', $userId)
                    ->where('type', 'gift_card_redeem')->where('amount', '<', 0)->exists();
                return false;
            }
        };
        app()->instance(UserService::class, $failure);
    }
    $connectionId = (int) $fixture->connection()->selectOne('SELECT CONNECTION_ID() AS id')->id;
    $emit(['event' => 'ready', 'pid' => getmypid(), 'connection_id' => $connectionId]);
    if (trim((string) fgets(STDIN)) !== 'GO') {
        throw new RuntimeException('The parent did not release the acceptance barrier.');
    }
    $lockMs = null;
    $fixture->connection()->setEventDispatcher(new Dispatcher(app()));
    $fixture->connection()->listen(static function (QueryExecuted $event) use (&$lockMs, $emit): void {
        if ($lockMs === null && stripos($event->sql, 'select') === 0
            && str_contains($event->sql, '`v2_gift_card_code`') && stripos($event->sql, 'for update') !== false) {
            $lockMs = $event->time;
            $emit(['event' => 'locked']);
            // This delay exists only in the isolated test worker, after the real locking read.
            usleep(600000);
        }
    });
    try {
        $service->redeem();
        $status = 'success';
    } catch (ApiException) {
        $status = 'rejected';
    }
    $emit(['event' => 'result', 'status' => $status, 'lock_query_ms' => $lockMs,
        'injected_failure_after_debit' => $failure?->debitSeen ?? false]);
} catch (Throwable $error) {
    $emit(['event' => 'error', 'type' => get_class($error)]);
    exit(1);
}
