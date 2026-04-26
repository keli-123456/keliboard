<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserGenerate;
use App\Http\Requests\Admin\UserSendMail;
use App\Http\Requests\Admin\UserUpdate;
use App\Jobs\SendEmailJob;
use App\Models\MarketingRule;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuthService;
use App\Services\TicketCleanupService;
use App\Services\UserService;
use App\Services\UserOnlineService;
use App\Traits\QueryOperators;
use App\Utils\Helper;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;

class UserController extends Controller
{
    use QueryOperators;

    private const USER_FILTER_FIELDS = [
        'id' => 'id',
        'email' => 'email',
        'remarks' => 'remarks',
        'token' => 'token',
        'uuid' => 'uuid',
        'invite_user_id' => 'invite_user_id',
        'plan_id' => 'plan_id',
        'group_id' => 'group_id',
        'group_ids' => 'group_id',
        'banned' => 'banned',
        'is_admin' => 'is_admin',
        'is_staff' => 'is_staff',
        'expired_at' => 'expired_at',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
        'last_login_at' => 'last_login_at',
        'balance' => 'balance',
        'commission_balance' => 'commission_balance',
        'commission_rate' => 'commission_rate',
        'commission_type' => 'commission_type',
        'discount' => 'discount',
        'transfer_enable' => 'transfer_enable',
        'u' => 'u',
        'd' => 'd',
        'total_used' => 'total_used',
        'speed_limit' => 'speed_limit',
        'device_limit' => 'device_limit',
        'online_count' => 'online_count',
    ];

    private const USER_RELATION_FILTER_FIELDS = [
        'invite_user.email' => ['invite_user', 'email'],
        'plan.name' => ['plan', 'name'],
        'group.name' => ['group', 'name'],
    ];

    private const USER_SORT_FIELDS = [
        'id' => 'id',
        'email' => 'email',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
        'expired_at' => 'expired_at',
        'last_login_at' => 'last_login_at',
        'transfer_enable' => 'transfer_enable',
        'balance' => 'balance',
        'commission_balance' => 'commission_balance',
        'online_count' => 'online_count',
        'total_used' => 'total_used',
    ];

    public function resetSecret(Request $request)
    {
        $user = User::find($request->input('id'));
        if (!$user)
            return $this->fail([400202, '用户不存在']);
        $user->token = Helper::guid();
        $user->uuid = Helper::guid(true);
        return $this->success($user->save());
    }

    /**
     * Apply filters and sorts to the query builder
     *
     * @param Request $request
     * @param Builder $builder
     * @return void
     */
    private function applyFiltersAndSorts(Request $request, Builder $builder): void
    {
        $this->applyFilters($request, $builder);
        $this->applySorting($request, $builder);
    }

    /**
     * Apply filters to the query builder
     *
     * @param Request $request
     * @param Builder $builder
     * @return void
     */
    private function applyFilters(Request $request, Builder $builder): void
    {
        $filters = $request->input('filter');
        if (!is_array($filters)) {
            return;
        }

        collect($filters)->each(function ($filter) use ($builder) {
            if (!is_array($filter) || !array_key_exists('id', $filter)) {
                return;
            }

            $field = trim((string) $filter['id']);
            if (!$this->isAllowedUserFilterField($field)) {
                return;
            }

            $value = $filter['value'] ?? null;

            $builder->where(function ($query) use ($field, $value) {
                $this->buildFilterQuery($query, $field, $value);
            });
        });
    }

    /**
     * Build the filter query based on field and value
     *
     * @param Builder $query
     * @param string $field
     * @param mixed $value
     * @return void
     */
    private function buildFilterQuery(Builder $query, string $field, mixed $value): void
    {
        // Keyword search across multiple fields (OR by whitespace tokens).
        if (in_array($field, ['keyword', 'q'], true)) {
            $raw = is_string($value) || is_numeric($value) ? trim((string) $value) : '';
            if ($raw === '') {
                return;
            }

            $tokens = preg_split('/\s+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $tokens = array_values(array_filter(array_map(fn($t) => trim((string) $t), $tokens)));

            $query->where(function ($outer) use ($tokens) {
                foreach ($tokens as $i => $token) {
                    $apply = $i === 0 ? 'where' : 'orWhere';
                    $outer->{$apply}(function ($q) use ($token) {
                        $q->where('email', 'like', "%{$token}%")
                            ->orWhere('remarks', 'like', "%{$token}%")
                            ->orWhere('token', 'like', "%{$token}%")
                            ->orWhere('uuid', 'like', "%{$token}%")
                            ->orWhereHas('invite_user', function ($sub) use ($token) {
                                $sub->where('email', 'like', "%{$token}%");
                            })
                            ->orWhereHas('plan', function ($sub) use ($token) {
                                $sub->where('name', 'like', "%{$token}%");
                            })
                            ->orWhereHas('group', function ($sub) use ($token) {
                                $sub->where('name', 'like', "%{$token}%");
                            });

                        if (is_numeric($token)) {
                            $n = (int) $token;
                            $q->orWhere('id', $n)
                                ->orWhere('invite_user_id', $n)
                                ->orWhere('plan_id', $n)
                                ->orWhere('group_id', $n);

                            // Fuzzy match numeric IDs (e.g., search "123" matches id 5123).
                            $q->orWhereRaw('CAST(id AS CHAR) LIKE ?', ["%{$token}%"])
                                ->orWhereRaw('CAST(invite_user_id AS CHAR) LIKE ?', ["%{$token}%"])
                                ->orWhereRaw('CAST(plan_id AS CHAR) LIKE ?', ["%{$token}%"])
                                ->orWhereRaw('CAST(group_id AS CHAR) LIKE ?', ["%{$token}%"]);
                        }
                    });
                }
            });
            return;
        }

        // 处理关联查询
        $relationFilter = $this->resolveUserRelationFilterField($field);
        if ($relationFilter !== null) {
            [$relation, $relationField] = $relationFilter;
            $query->whereHas($relation, function ($q) use ($relationField, $value) {
                if (is_array($value)) {
                    $q->whereIn($relationField, $value);
                } else if (is_string($value) && str_contains($value, ':')) {
                    [$operator, $filterValue] = explode(':', $value, 2);
                    $this->applyQueryCondition($q, $relationField, $operator, $filterValue);
                } else {
                    $q->where($relationField, 'like', "%{$value}%");
                }
            });
            return;
        }

        $queryField = $this->resolveUserFilterField($field);
        if ($queryField === null) {
            return;
        }

        // 处理数组值的 'in' 操作
        if (is_array($value)) {
            $query->whereIn($queryField, $value);
            return;
        }

        // 处理基于运算符的过滤
        if (!is_string($value) || !str_contains($value, ':')) {
            $query->where($queryField, 'like', "%{$value}%");
            return;
        }

        [$operator, $filterValue] = explode(':', $value, 2);

        // 转换数字字符串为适当的类型
        if (is_numeric($filterValue)) {
            $filterValue = strpos($filterValue, '.') !== false
                ? (float) $filterValue
                : (int) $filterValue;
        }

        // 处理计算字段
        $this->applyQueryCondition($query, $queryField, $operator, $filterValue);
    }

    /**
     * Apply sorting to the query builder
     *
     * @param Request $request
     * @param Builder $builder
     * @return void
     */
    private function applySorting(Request $request, Builder $builder): void
    {
        $sorts = $request->input('sort');
        if (!is_array($sorts)) {
            return;
        }

        collect($sorts)->each(function ($sort) use ($builder) {
            if (!is_array($sort) || !array_key_exists('id', $sort)) {
                return;
            }

            $field = $this->resolveUserSortField(trim((string) $sort['id']));
            if ($field === null) {
                return;
            }

            $direction = !empty($sort['desc']) ? 'DESC' : 'ASC';
            $builder->orderBy($field, $direction);
        });
    }

    private function isAllowedUserFilterField(string $field): bool
    {
        return in_array($field, ['keyword', 'q'], true)
            || isset(self::USER_FILTER_FIELDS[$field])
            || isset(self::USER_RELATION_FILTER_FIELDS[$field]);
    }

    private function resolveUserFilterField(string $field): Expression|string|null
    {
        if ($field === 'total_used') {
            return DB::raw('(u + d)');
        }

        return self::USER_FILTER_FIELDS[$field] ?? null;
    }

    private function resolveUserRelationFilterField(string $field): ?array
    {
        return self::USER_RELATION_FILTER_FIELDS[$field] ?? null;
    }

    private function resolveUserSortField(string $field): Expression|string|null
    {
        if ($field === 'total_used') {
            return DB::raw('(u + d)');
        }

        return self::USER_SORT_FIELDS[$field] ?? null;
    }

    /**
     * Fetch paginated user list with filters and sorting
     *
     * @param Request $request
     * @return \Illuminate\Http\Response
     */
    public function fetch(Request $request)
    {
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);

        $userModel = User::with(['plan:id,name', 'invite_user:id,email', 'group:id,name'])
            ->select(DB::raw('*, (u+d) as total_used'));

        $this->applyFiltersAndSorts($request, $userModel);

        $users = $userModel->orderBy('id', 'desc')
            ->paginate($pageSize, ['*'], 'page', $current);

        $userIds = $users->getCollection()->pluck('id')->all();
        $onlineCounts = app(UserOnlineService::class)->getOnlineCounts($userIds);

        $users->getCollection()->transform(function ($user) use ($onlineCounts): array {
            $data = self::transformUserData($user);
            $data['online_ip_count'] = $onlineCounts[$user->id] ?? 0;
            return $data;
        });

        return $this->paginate($users);
    }

    /**
     * Transform user data for response
     *
     * @param User $user
     * @return array<string, mixed>
     */
    public static function transformUserData(User $user): array
    {
        $user = $user->toArray();
        $user['balance'] = $user['balance'] / 100;
        $user['commission_balance'] = $user['commission_balance'] / 100;
        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token']);
        return $user;
    }

    public function getUserInfoById(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric'
        ], [
            'id.required' => '用户ID不能为空'
        ]);
        $user = User::find($request->input('id'));
        if (!$user) {
            return $this->fail([400202, '用户不存在']);
        }

        $user->load('invite_user');
        return $this->success($user);
    }

    public function getOnlineDevices(Request $request): JsonResponse
    {
        $request->validate([
            'id' => 'required|integer|min:1'
        ], [
            'id.required' => '用户ID不能为空'
        ]);

        $userId = (int) $request->input('id');
        if (!User::whereKey($userId)->exists()) {
            return $this->fail([400202, '用户不存在']);
        }

        return $this->success(UserOnlineService::getUserDeviceIps($userId));
    }

    public function update(UserUpdate $request)
    {
        $params = $request->validated();

        $user = User::find($request->input('id'));
        if (!$user) {
            return $this->fail([400202, '用户不存在']);
        }
        if (isset($params['email'])) {
            if (User::where('email', $params['email'])->first() && $user->email !== $params['email']) {
                return $this->fail([400201, '邮箱已被使用']);
            }
        }
        // 处理密码
        if (isset($params['password'])) {
            $params['password'] = password_hash($params['password'], PASSWORD_DEFAULT);
            $params['password_algo'] = NULL;
        } else {
            unset($params['password']);
        }
        // 处理订阅计划
        if (array_key_exists('plan_id', $params)) {
            if ($params['plan_id'] === null) {
                $params['group_id'] = null;
            } else {
                $plan = Plan::find((int) $params['plan_id']);
                if (!$plan) {
                    return $this->fail([400202, '订阅计划不存在']);
                }
                $params['group_id'] = $plan->group_id;
            }
        }
        // 处理邀请用户：只有显式提交该字段时才变更邀请关系，避免局部更新误清空
        if (array_key_exists('invite_user_email', $request->all())) {
            $inviteUserEmail = $request->input('invite_user_email');
            if ($inviteUserEmail) {
                $inviteUser = User::where('email', $inviteUserEmail)->first();
                if (!$inviteUser) {
                    return $this->fail([400202, '邀请用户不存在']);
                }
                if ((int) $inviteUser->id === (int) $user->id) {
                    return $this->fail([400, '不能将自己设置为邀请人']);
                }
                $params['invite_user_id'] = $inviteUser->id;
            } else {
                $params['invite_user_id'] = null;
            }
        }

        if (isset($params['banned']) && (int) $params['banned'] === 1) {
            $authService = new AuthService($user);
            $authService->removeAllSessions();
        }
        if (isset($params['balance'])) {
            $params['balance'] = $params['balance'] * 100;
        }
        if (isset($params['commission_balance'])) {
            $params['commission_balance'] = $params['commission_balance'] * 100;
        }

        try {
            $user->update($params);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }
        return $this->success(true);
    }

    /**
     * 导出用户数据为CSV格式
     *
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function dumpCSV(Request $request)
    {
        gc_enable(); // 启用垃圾回收

        // 优化查询：使用with预加载plan关系，避免N+1问题
        $query = User::with('plan:id,name')
            ->orderBy('id', 'asc')
            ->select([
                'id',
                'email',
                'balance',
                'commission_balance',
                'transfer_enable',
                'u',
                'd',
                'expired_at',
                'token',
                'plan_id'
            ]);

        $this->applyFiltersAndSorts($request, $query);

        $filename = 'users_' . date('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            // 打开输出流
            $output = fopen('php://output', 'w');

            // 添加BOM标记，确保Excel正确显示中文
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // 写入CSV头部
            fputcsv($output, [
                '邮箱',
                '余额',
                '推广佣金',
                '总流量',
                '剩余流量',
                '套餐到期时间',
                '订阅计划',
                '订阅地址'
            ]);

            // 分批处理数据以减少内存使用
            $query->chunk(500, function ($users) use ($output) {
                foreach ($users as $user) {
                    try {
                        $row = [
                            $user->email,
                            number_format($user->balance / 100, 2),
                            number_format($user->commission_balance / 100, 2),
                            Helper::trafficConvert($user->transfer_enable),
                            Helper::trafficConvert($user->transfer_enable - ($user->u + $user->d)),
                            $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '长期有效',
                            $user->plan ? $user->plan->name : '无订阅',
                            Helper::getSubscribeUrl($user->token)
                        ];
                        fputcsv($output, $row);
                    } catch (\Exception $e) {
                        Log::error('CSV导出错误: ' . $e->getMessage(), [
                            'user_id' => $user->id,
                            'email' => $user->email
                        ]);
                        continue; // 继续处理下一条记录
                    }
                }

                // 清理内存
                gc_collect_cycles();
            });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"'
        ]);
    }

    public function generate(UserGenerate $request)
    {
        if ($request->input('email_prefix')) {
            $email = $request->input('email_prefix') . '@' . $request->input('email_suffix');

            if (User::where('email', $email)->exists()) {
                return $this->fail([400201, '邮箱已存在于系统中']);
            }

            $userService = app(UserService::class);
            $user = $userService->createUser([
                'email' => $email,
                'password' => $request->input('password') ?? $email,
                'plan_id' => $request->input('plan_id'),
                'expired_at' => $request->input('expired_at'),
            ]);

            if (!$user->save()) {
                return $this->fail([500, '生成失败']);
            }
            return $this->success(true);
        }

        if ($request->input('generate_count')) {
            return $this->multiGenerate($request);
        }
    }

    private function multiGenerate(Request $request)
    {
        $userService = app(UserService::class);
        $generatedUsers = [];
        $generateCount = max(0, (int) $request->input('generate_count'));
        $emailSuffix = (string) $request->input('email_suffix');
        $passwordInput = $request->input('password');
        $planId = $request->input('plan_id');
        $expiredAt = $request->input('expired_at');
        $reservedEmails = [];

        try {
            DB::beginTransaction();

            for ($i = 0; $i < $generateCount; $i++) {
                $email = $this->makeGeneratedEmail($emailSuffix, $reservedEmails);
                $password = $passwordInput ?? $email;
                $user = $userService->createUser([
                    'email' => $email,
                    'password' => $password,
                    'plan_id' => $planId,
                    'expired_at' => $expiredAt,
                ]);
                $user->save();
                $generatedUsers[] = [
                    'email' => $user->email,
                    'password' => $password,
                    'expired_at' => $user->expired_at === null ? '长期有效' : date('Y-m-d H:i:s', $user->expired_at),
                    'uuid' => $user->uuid,
                    'created_at' => date('Y-m-d H:i:s', $user->created_at),
                    'subscribe_url' => Helper::getSubscribeUrl($user->token),
                ];
            }

            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            Log::error($e);
            return $this->fail([500, '生成失败']);
        }

        // 判断是否导出 CSV
        if ($request->input('download_csv')) {
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="users.csv"',
            ];
            $callback = function () use ($generatedUsers) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['账号', '密码', '过期时间', 'UUID', '创建时间', '订阅地址']);
                foreach ($generatedUsers as $user) {
                    fputcsv($handle, [
                        $user['email'],
                        $user['password'],
                        $user['expired_at'],
                        $user['uuid'],
                        $user['created_at'],
                        $user['subscribe_url'],
                    ]);
                }
                fclose($handle);
            };
            return response()->streamDownload($callback, 'users.csv', $headers);
        }

        // 默认返回 JSON
        return response()->json([
            'code' => 0,
            'message' => '批量生成成功',
            'data' => $generatedUsers,
        ]);
    }

    private function makeGeneratedEmail(string $emailSuffix, array &$reservedEmails): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $email = Helper::randomChar(6) . '@' . $emailSuffix;
            if (isset($reservedEmails[$email])) {
                continue;
            }

            if (User::where('email', $email)->exists()) {
                continue;
            }

            $reservedEmails[$email] = true;
            return $email;
        }

        throw new \RuntimeException('Unable to generate a unique user email');
    }

    public function sendMail(UserSendMail $request)
    {
        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $this->resolveUserSortField((string) ($request->input('sort') ?: 'created_at')) ?? 'created_at';
        $builder = User::orderBy($sort, $sortType);
        $this->applyFiltersAndSorts($request, $builder);

        $subject = $request->input('subject');
        $content = $request->input('content');
        $templateValue = [
            'name' => admin_setting('app_name', 'XBoard'),
            'url' => admin_setting('app_url'),
            'content' => $content
        ];

        $chunkSize = 500;

        $builder->chunk($chunkSize, function ($users) use ($subject, $templateValue) {
            foreach ($users as $user) {
                dispatch(new SendEmailJob([
                    'email' => $user->email,
                    'subject' => $subject,
                    'message_type' => MarketingRule::TYPE_MARKETING,
                    'template_name' => 'notify',
                    'template_value' => $templateValue
                ], 'send_email_mass'));
            }
        });

        return $this->success(true);
    }

    public function ban(Request $request)
    {
        $ids = $request->input('ids');
        $hasIds = is_array($ids) && count($ids) > 0;
        $filters = $request->input('filter');
        $hasFilters = is_array($filters) && count($filters) > 0;

        if (!$hasIds && !$hasFilters && !$request->boolean('confirm_all')) {
            return $this->fail([400, '批量封禁全部用户需要确认']);
        }
        if (!$hasIds && $hasFilters && !$request->boolean('confirm_filter')) {
            return $this->fail([400, '批量封禁筛选结果需要确认']);
        }

        $builder = User::query()->where('is_admin', 0);
        if ($hasIds) {
            $builder->whereIn('id', array_values(array_unique(array_map('intval', $ids))));
        } else {
            $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
            $sort = $this->resolveUserSortField((string) ($request->input('sort') ?: 'created_at')) ?? 'created_at';
            $builder->orderBy($sort, $sortType);
            $this->applyFilters($request, $builder);
        }

        if ($request->user()) {
            $builder->where('id', '<>', $request->user()->id);
        }

        try {
            $userIds = (clone $builder)->pluck('id');
            $builder->update([
                'banned' => 1
            ]);
            if ($userIds->isNotEmpty()) {
                PersonalAccessToken::where('tokenable_type', User::class)
                    ->whereIn('tokenable_id', $userIds)
                    ->delete();
            }
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '处理失败']);
        }

        return $this->success([
            'affected_count' => $userIds->count(),
        ]);
    }

    /**
     * 删除用户及其关联数据
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function destroy(Request $request, TicketCleanupService $ticketCleanupService)
    {
        $request->validate([
            'id' => 'required|exists:App\Models\User,id'
        ], [
            'id.required' => '用户ID不能为空',
            'id.exists' => '用户不存在'
        ]);
        $user = User::find($request->input('id'));
        if (!$user) {
            return $this->fail([400202, '用户不存在']);
        }

        $ticketAttachments = collect();
        try {
            DB::beginTransaction();

            $ticketIds = $user->tickets()->pluck('id')->map(fn ($id) => (int) $id)->all();
            $ticketAttachments = $ticketCleanupService->collectAttachmentsByTicketIds($ticketIds);
            $user->orders()->delete();
            $user->codes()->delete();
            $user->stat()->delete();
            $ticketCleanupService->deleteRowsByTicketIds($ticketIds);
            $user->delete();
            DB::commit();
            $ticketCleanupService->deleteAttachmentFiles($ticketAttachments);
            return $this->success(true);
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            Log::error($e);
            return $this->fail([500, '删除失败']);
        }
    }
}
