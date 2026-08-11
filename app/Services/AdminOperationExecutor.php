<?php

namespace App\Services;

use App\Http\Requests\Admin\ServerSave;
use App\Models\AdminOperationTask;
use App\Models\AdminOperationTaskItem;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Services\NodeRealtime\NodeRealtimePublisher;
use App\Services\ServerMachine\BatchBindingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AdminOperationExecutor
{
    public const USER_SET_PLAN = 'user.set_plan';
    public const USER_SET_EXPIRED_AT = 'user.set_expired_at';
    public const USER_SET_BALANCE = 'user.set_balance';
    public const USER_SET_TRANSFER_ENABLE = 'user.set_transfer_enable';
    public const USER_SET_DEVICE_LIMIT = 'user.set_device_limit';
    public const USER_RESET_TRAFFIC = 'user.reset_traffic';
    public const USER_DELETE = 'user.delete';
    public const SERVER_SAVE = 'server.save';
    public const MACHINE_BATCH_BIND = 'machine.batch_bind';

    public const SUPPORTED_OPERATIONS = [
        self::USER_SET_PLAN,
        self::USER_SET_EXPIRED_AT,
        self::USER_SET_BALANCE,
        self::USER_SET_TRANSFER_ENABLE,
        self::USER_SET_DEVICE_LIMIT,
        self::USER_RESET_TRAFFIC,
        self::USER_DELETE,
        self::SERVER_SAVE,
        self::MACHINE_BATCH_BIND,
    ];

    public function __construct(
        private readonly TrafficResetService $trafficResetService,
        private readonly TicketCleanupService $ticketCleanupService,
        private readonly NodeRealtimePublisher $nodeRealtimePublisher,
        private readonly BatchBindingService $batchBindingService,
    ) {
    }

    public function validateTaskPayload(string $operation, array $payload): array
    {
        if (!in_array($operation, self::SUPPORTED_OPERATIONS, true)) {
            throw ValidationException::withMessages(['operation' => ['Unsupported operation type.']]);
        }

        if ($operation === self::MACHINE_BATCH_BIND) {
            return $this->batchBindingService->normalizePayload($payload);
        }

        $rules = match ($operation) {
            self::USER_SET_PLAN => ['plan_id' => 'present|nullable|integer|min:1'],
            self::USER_SET_EXPIRED_AT => ['expired_at' => 'present|nullable|integer|min:0'],
            self::USER_SET_BALANCE => ['balance' => 'required|numeric'],
            self::USER_SET_TRANSFER_ENABLE => ['transfer_enable' => 'required|integer|min:0'],
            self::USER_SET_DEVICE_LIMIT => ['device_limit' => 'present|nullable|integer|min:0'],
            self::USER_RESET_TRAFFIC => ['reason' => 'nullable|string|max:255'],
            default => [],
        };

        $validated = Validator::make($payload, $rules)->validate();
        if ($operation === self::USER_SET_PLAN && $validated['plan_id'] !== null) {
            if (!Plan::query()->whereKey((int) $validated['plan_id'])->exists()) {
                throw ValidationException::withMessages(['payload.plan_id' => ['Plan does not exist.']]);
            }
        }

        return $validated;
    }

    public function execute(AdminOperationTask $task, AdminOperationTaskItem $item): array
    {
        $taskPayload = is_array($task->payload) ? $task->payload : [];

        return match ($task->operation) {
            self::USER_SET_PLAN => $this->setUserPlan((int) $item->item_key, $taskPayload['plan_id'] ?? null),
            self::USER_SET_EXPIRED_AT => $this->updateUserField((int) $item->item_key, 'expired_at', $taskPayload['expired_at'] ?? null),
            self::USER_SET_BALANCE => $this->updateUserField(
                (int) $item->item_key,
                'balance',
                (int) round(((float) $taskPayload['balance']) * 100)
            ),
            self::USER_SET_TRANSFER_ENABLE => $this->updateUserField(
                (int) $item->item_key,
                'transfer_enable',
                (int) $taskPayload['transfer_enable']
            ),
            self::USER_SET_DEVICE_LIMIT => $this->updateUserField(
                (int) $item->item_key,
                'device_limit',
                $taskPayload['device_limit'] ?? null
            ),
            self::USER_RESET_TRAFFIC => $this->resetUserTraffic(
                (int) $item->item_key,
                (string) ($taskPayload['reason'] ?? ''),
                (int) $task->admin_id,
                (string) $task->id
            ),
            self::USER_DELETE => $this->deleteUser((int) $item->item_key),
            self::SERVER_SAVE => $this->saveServer($item),
            self::MACHINE_BATCH_BIND => $this->machineBatchBind($taskPayload),
            default => throw new RuntimeException('Unsupported operation type.'),
        };
    }

    private function setUserPlan(int $userId, mixed $planId): array
    {
        $user = User::query()->find($userId);
        if (!$user) {
            return $this->skipped('user_missing');
        }

        if ($planId === null) {
            $user->update(['plan_id' => null, 'group_id' => null]);
            return $this->succeeded(['user_id' => $userId, 'plan_id' => null]);
        }

        $plan = Plan::query()->find((int) $planId);
        if (!$plan) {
            throw new RuntimeException('Plan does not exist.');
        }
        $user->update(['plan_id' => (int) $plan->id, 'group_id' => $plan->group_id]);

        return $this->succeeded(['user_id' => $userId, 'plan_id' => (int) $plan->id]);
    }

    private function updateUserField(int $userId, string $field, mixed $value): array
    {
        $user = User::query()->find($userId);
        if (!$user) {
            return $this->skipped('user_missing');
        }
        $user->update([$field => $value]);

        return $this->succeeded(['user_id' => $userId, 'field' => $field]);
    }

    private function resetUserTraffic(int $userId, string $reason, int $adminId, string $taskId): array
    {
        $user = User::query()->find($userId);
        if (!$user) {
            return $this->skipped('user_missing');
        }
        if (!$this->trafficResetService->canReset($user)) {
            throw new RuntimeException('User traffic cannot be reset.');
        }

        $metadata = [
            'admin_id' => $adminId,
            'operation_task_id' => $taskId,
        ];
        if ($reason !== '') {
            $metadata['reason'] = $reason;
        }
        if (!$this->trafficResetService->manualReset($user, $metadata)) {
            throw new RuntimeException('Traffic reset failed.');
        }

        return $this->succeeded(['user_id' => $userId]);
    }

    private function deleteUser(int $userId): array
    {
        $user = User::query()->find($userId);
        if (!$user) {
            return $this->skipped('user_missing');
        }

        $email = (string) $user->email;
        $ticketIds = $user->tickets()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $attachments = $this->ticketCleanupService->collectAttachmentsByTicketIds($ticketIds);
        $user->orders()->delete();
        $user->codes()->delete();
        $user->stat()->delete();
        $this->ticketCleanupService->deleteRowsByTicketIds($ticketIds);
        $user->delete();

        DB::afterCommit(fn () => $this->ticketCleanupService->deleteAttachmentFiles($attachments));

        return $this->succeeded(['user_id' => $userId, 'email' => $email]);
    }

    private function saveServer(AdminOperationTaskItem $item): array
    {
        $payload = is_array($item->payload) ? $item->payload : [];
        $serverId = (int) ($payload['id'] ?? $item->item_key);
        $server = Server::query()->find($serverId);
        if (!$server) {
            return $this->skipped('server_missing');
        }

        $request = ServerSave::create('/', 'POST', array_merge($payload, ['id' => $serverId]));
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->validateResolved();
        $params = $request->validated();

        $oldGroupIds = $this->normalizeIds((array) ($server->group_ids ?? []));
        $server->update($params);
        $newGroupIds = $this->normalizeIds((array) ($server->group_ids ?? []));
        $this->nodeRealtimePublisher->invalidateConfigForServers([$serverId], 'admin.operation.server.saved');
        if ($oldGroupIds !== $newGroupIds) {
            $this->nodeRealtimePublisher->invalidateUsersForServers([$serverId], 'admin.operation.server.groups_saved');
        }

        return $this->succeeded(['server_id' => $serverId]);
    }

    public function riskLevel(string $operation, array $payload = []): string
    {
        if ($operation === self::USER_DELETE) {
            return 'danger';
        }
        if ($operation === self::MACHINE_BATCH_BIND && (bool) ($payload['allow_transfer'] ?? false)) {
            return 'danger';
        }
        if ($operation === self::USER_RESET_TRAFFIC) {
            return 'warning';
        }
        if ($operation === self::MACHINE_BATCH_BIND && ($payload['mode'] ?? 'replace') === 'replace') {
            return 'warning';
        }

        return 'normal';
    }

    private function machineBatchBind(array $payload): array
    {
        return $this->succeeded($this->batchBindingService->bind($payload));
    }

    private function normalizeIds(array $values): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(fn ($value) => (int) $value, $values),
            fn (int $value) => $value > 0
        )));
        sort($ids);

        return $ids;
    }

    private function succeeded(array $result = []): array
    {
        return ['skipped' => false, 'result' => $result];
    }

    private function skipped(string $reason): array
    {
        return ['skipped' => true, 'result' => ['reason' => $reason]];
    }
}
