<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Bounded, read-only account evidence. No messages, credentials or raw node names. */
class TicketAiAccountEvidenceService
{
    public static function question(array $conversation, string $subject): string
    {
        $parts = [mb_substr($subject, 0, 300)];
        $userMessages = array_values(array_filter($conversation, fn ($message) => ($message['role'] ?? null) === 'user'));
        foreach (array_slice($userMessages, -12) as $message) {
            $parts[] = mb_substr((string) ($message['content'] ?? ''), 0, 1000);
        }

        return mb_strtolower(implode("\n", $parts));
    }

    public static function intents(string $question): array
    {
        $intents = [];
        foreach (['流量突然', '流量一下', '流量没了', '流量去哪', '流量记录', '流量明细', '流量消耗', '扣流量', '跑流量', '流量用量', '流量不对', '用了哪些节点', 'traffic usage', 'data usage', 'traffic history'] as $needle) {
            if (str_contains($question, $needle)) {
                $intents[] = 'traffic_history';
                break;
            }
        }
        if (!in_array('traffic_history', $intents, true) && str_contains($question, '流量')
            && preg_match('/用完|用光|耗尽|消耗|突然|一下|没了|没有了|去哪|明细|记录|扣|为什么|怎么/u', $question)) {
            $intents[] = 'traffic_history';
        }
        if ((str_contains($question, '工单') && preg_match('/历史|之前|以前|前[两二三几个]|关闭|没关|没有关|谁关|关单/u', $question))
            || str_contains($question, 'previous ticket') || str_contains($question, 'closed ticket')) {
            $intents[] = 'ticket_history';
        }

        return $intents;
    }

    public function build(?User $user, Ticket $ticket, array $conversation): array
    {
        $question = self::question($conversation, (string) $ticket->subject);
        $intents = self::intents($question);
        $result = [
            'mode' => 'backend_verified_read_only', 'checked_at' => time(), 'intents' => $intents,
            'timezone' => config('app.timezone', 'UTC'),
            'status' => 'not_requested', 'requires_human' => false, 'facts' => [], 'tools' => [],
        ];
        if ($intents === []) {
            return $result;
        }
        if (!$this->authorized($user, $ticket)) {
            return array_merge($result, [
                'status' => 'scope_mismatch', 'requires_human' => true,
                'facts' => [['label' => '查询范围', 'value' => '当前工单与账户归属无法一致核验，未读取历史数据，需要人工核查。']],
            ]);
        }
        if (in_array('traffic_history', $intents, true)) {
            $result['tools']['daily_traffic'] = $this->daily((int) $user->id);
            $result['tools']['node_traffic'] = $this->nodes((int) $user->id);
            $result['tools']['traffic_resets'] = $this->resets((int) $user->id);
            $result['facts'] = $this->trafficFacts($result['tools']);
            $result['requires_human'] = $result['tools']['daily_traffic']['status'] !== 'available'
                && $result['tools']['node_traffic']['status'] !== 'available';
            if (str_contains($question, '节点') && $result['tools']['node_traffic']['status'] !== 'available') {
                $result['requires_human'] = true;
            }
        }
        if (in_array('ticket_history', $intents, true)) {
            $history = $result['tools']['ticket_history'] = $this->tickets($ticket);
            if ($history['status'] === 'unavailable') {
                $result['requires_human'] = true;
            }
            foreach ($history['records'] as $row) {
                $result['facts'][] = [
                    'label' => '历史工单 #' . $row['ticket_id'],
                    'value' => ($row['status'] === 1 ? '已关闭' : ($row['status'] === 0 ? '进行中' : '状态未知'))
                        . '，创建于 ' . $row['created_at'] . '，最后更新于 ' . $row['updated_at'],
                ];
            }
            if ($history['records'] === []) {
                $result['facts'][] = ['label' => '历史工单', 'value' => $history['status'] === 'unavailable'
                    ? '历史状态暂时无法读取，不能认定没有历史工单。' : '最近90天同一站点和代理范围内未查到其他工单，不代表更早没有工单。'];
            }
            $result['facts'][] = ['label' => '关闭记录边界', 'value' => '工单更新时间不是关闭时间；当前状态记录不能证明关闭操作人、关闭原因或用户是否点击过关闭。'];
            if (preg_match('/谁关|没有关|没关|为什么|原因|自动关|who closed|why.*closed/u', $question)) {
                $result['requires_human'] = true;
            }
        }
        $result['status'] = collect($result['tools'])->contains(fn ($tool) => $tool['status'] === 'unavailable') ? 'partial' : 'available';

        return $result;
    }

    private function authorized(?User $user, Ticket $ticket): bool
    {
        if (!$user || (int) $user->id <= 0 || (int) $ticket->user_id !== (int) $user->id
            || (int) ($ticket->site_id ?? 0) !== (int) ($user->site_id ?? 0)) {
            return false;
        }
        if ((int) $ticket->agent_user_id > 0) {
            if ((int) $user->parent_id !== (int) $ticket->agent_user_id) {
                return false;
            }
            if ((int) $ticket->agent_domain_id > 0 && (!$ticket->relationLoaded('agentDomain')
                || !$ticket->agentDomain || (int) $ticket->agentDomain->agent_user_id !== (int) $ticket->agent_user_id)) {
                return false;
            }
        }

        return true;
    }

    private function start(): Carbon
    {
        return Carbon::now(config('app.timezone', 'UTC'))->startOfDay()->subDays(29);
    }

    private function read(string $table, callable $query): array
    {
        try {
            if (!Schema::hasTable($table)) {
                return ['status' => 'unavailable', 'records' => []];
            }
            $records = $query();

            return ['status' => $records === [] ? 'no_records' : 'available', 'records' => $records];
        } catch (\Throwable) {
            return ['status' => 'unavailable', 'records' => []];
        }
    }

    private function daily(int $userId): array
    {
        return $this->read('v2_stat_user', function () use ($userId): array {
            return DB::table('v2_stat_user')->where('user_id', $userId)->where('record_type', 'd')
                ->whereBetween('record_at', [$this->start()->timestamp, time()])
                ->selectRaw('record_at,SUM(u) AS upload,SUM(d) AS download,MAX(updated_at) AS reported_at')
                ->groupBy('record_at')->orderByDesc('record_at')->limit(30)->get()->map(fn ($row) => [
                    'date' => $this->date((int) $row->record_at), 'billed_upload_bytes' => TrafficBatchPayload::storedInteger($row->upload),
                    'billed_download_bytes' => TrafficBatchPayload::storedInteger($row->download),
                    'billed_bytes' => TrafficBatchPayload::add(TrafficBatchPayload::storedInteger($row->upload), TrafficBatchPayload::storedInteger($row->download)),
                    'last_reported_at' => (int) $row->reported_at,
                ])->all();
        });
    }

    private function nodes(int $userId): array
    {
        return $this->read('v2_stat_user_node_day', function () use ($userId): array {
            return DB::table('v2_stat_user_node_day')->where('user_id', $userId)->where('record_type', 'd')
                ->whereBetween('record_at', [$this->start()->timestamp, time()])
                ->selectRaw('server_id,server_type,SUM(u) AS upload,SUM(d) AS download,MIN(server_rate) AS min_rate,MAX(server_rate) AS max_rate,MAX(updated_at) AS reported_at')
                ->groupBy('server_id', 'server_type')->orderByRaw('SUM(u) + SUM(d) DESC')->limit(5)->get()->map(fn ($row) => [
                    'node_ref' => '节点 #' . (int) $row->server_id,
                    'protocol' => in_array($row->server_type, ['shadowsocks', 'vmess', 'vless', 'trojan', 'hysteria', 'hysteria2', 'tuic', 'anytls', 'naive', 'trusttunnel'], true) ? $row->server_type : 'unknown',
                    'billed_bytes' => TrafficBatchPayload::add(TrafficBatchPayload::storedInteger($row->upload), TrafficBatchPayload::storedInteger($row->download)),
                    'min_rate' => max(0, (float) $row->min_rate), 'max_rate' => max(0, (float) $row->max_rate),
                    'last_reported_at' => (int) $row->reported_at,
                ])->all();
        });
    }

    private function resets(int $userId): array
    {
        return $this->read('v2_traffic_reset_logs', function () use ($userId): array {
            return DB::table('v2_traffic_reset_logs')->where('user_id', $userId)->whereBetween('reset_time', [$this->start()->toDateTimeString(), Carbon::now(config('app.timezone', 'UTC'))->toDateTimeString()])
                ->orderByDesc('reset_time')->limit(3)->get(['reset_time', 'old_upload', 'old_download', 'new_upload', 'new_download', 'trigger_source'])->map(fn ($row) => [
                    'reset_at' => Carbon::parse($row->reset_time, config('app.timezone', 'UTC'))->format('Y-m-d H:i:s'),
                    'before_used_bytes' => TrafficBatchPayload::add(TrafficBatchPayload::storedInteger($row->old_upload), TrafficBatchPayload::storedInteger($row->old_download)),
                    'after_used_bytes' => TrafficBatchPayload::add(TrafficBatchPayload::storedInteger($row->new_upload), TrafficBatchPayload::storedInteger($row->new_download)),
                    'source' => ['auto' => '自动重置', 'manual' => '人工重置', 'api' => '接口重置', 'cron' => '定时重置', 'user_access' => '到期补重置', 'order' => '订单触发', 'gift_card' => '礼品卡触发'][$row->trigger_source] ?? '未知来源',
                ])->all();
        });
    }

    private function tickets(Ticket $ticket): array
    {
        return $this->read('v2_ticket', function () use ($ticket): array {
            $query = DB::table('v2_ticket')->where('user_id', $ticket->user_id)->where('id', '!=', $ticket->id)
                ->whereBetween('created_at', [time() - 90 * 86400, time()]);
            foreach (['site_id', 'agent_user_id', 'agent_domain_id'] as $key) {
                if (!Schema::hasColumn('v2_ticket', $key)) {
                    if ((int) $ticket->$key > 0) {
                        throw new \RuntimeException('scope_storage_missing');
                    }
                    continue;
                }
                if ((int) $ticket->$key > 0) {
                    $query->where($key, (int) $ticket->$key);
                } else {
                    $query->where(fn ($q) => $q->whereNull($key)->orWhere($key, 0));
                }
            }
            return $query->orderByDesc('created_at')->orderByDesc('id')->limit(5)->get(['id', 'status', 'created_at', 'updated_at'])->map(fn ($row) => [
                'ticket_id' => (int) $row->id, 'status' => (int) $row->status,
                'created_at' => $this->date((int) $row->created_at, true), 'updated_at' => $this->date((int) $row->updated_at, true),
            ])->all();
        });
    }

    private function trafficFacts(array $tools): array
    {
        $facts = [['label' => '流量统计范围', 'value' => $this->start()->format('Y-m-d') . ' 至 ' . $this->date(time()) . '，按日汇总；节点明细仅列计费用量最多的5个节点。']];
        $reported = array_merge(array_column($tools['daily_traffic']['records'], 'last_reported_at'), array_column($tools['node_traffic']['records'], 'last_reported_at'));
        if ($reported !== [] && max($reported) > 0) {
            $facts[] = ['label' => '本次统计最近更新', 'value' => $this->date((int) max($reported), true) . '（' . config('app.timezone', 'UTC') . '），不代表全部节点均已完整上报。'];
        }
        $days = $tools['daily_traffic']['records'];
        usort($days, fn ($a, $b) => $b['billed_bytes'] <=> $a['billed_bytes']);
        foreach (array_slice($days, 0, 3) as $day) {
            $facts[] = ['label' => '较高用量日 ' . $day['date'], 'value' => '记录的计费用量 ' . $this->bytes($day['billed_bytes'])];
        }
        if ($days === []) {
            $facts[] = ['label' => '逐日流量', 'value' => $tools['daily_traffic']['status'] === 'unavailable' ? '逐日记录暂不可读取，不能按零流量计算。' : '本次范围未查到逐日记录，不等于没有发生流量。'];
        }
        foreach ($tools['node_traffic']['records'] as $node) {
            $facts[] = ['label' => $node['node_ref'] . '（' . $node['protocol'] . '）',
                'value' => '记录的计费用量 ' . $this->bytes($node['billed_bytes']) . '，记录倍率 ' . number_format($node['min_rate'], 2, '.', '') . '-' . number_format($node['max_rate'], 2, '.', '') . '；倍率已计入用量，不能再次相乘。'];
        }
        if ($tools['node_traffic']['records'] === []) {
            $facts[] = ['label' => '节点用量', 'value' => $tools['node_traffic']['status'] === 'unavailable' ? '节点明细暂不可读取。' : '本次范围未查到节点明细，不等于没有使用节点。'];
        }
        foreach ($tools['traffic_resets']['records'] as $reset) {
            $facts[] = ['label' => '已用流量重置 ' . $reset['reset_at'],
                'value' => $reset['source'] . '，累计已用从 ' . $this->bytes($reset['before_used_bytes']) . ' 变为 ' . $this->bytes($reset['after_used_bytes']) . '；不是扣除了套餐总额度。'];
        }
        if ($tools['traffic_resets']['records'] === []) {
            $facts[] = ['label' => '重置记录', 'value' => $tools['traffic_resets']['status'] === 'unavailable' ? '重置审计暂不可读取，不能判断是否重置过。' : '本次30天范围内未查到已用流量重置记录。'];
        }
        $facts[] = ['label' => '核验边界', 'value' => '统计可能延迟上报，历史窗口不等于当前计费周期；不能拿日汇总直接还原重置前后或小时级消耗。记录不包含具体App、访问网站或关闭操作人，不能据此断言盗用、错误扣费或承诺补偿。'];

        return $facts;
    }

    private function date(int $timestamp, bool $time = false): string
    {
        if ($timestamp <= 0) {
            return '未知';
        }

        return Carbon::createFromTimestamp($timestamp, config('app.timezone', 'UTC'))->format($time ? 'Y-m-d H:i:s' : 'Y-m-d');
    }

    private function bytes(int $value): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $index = 0;
        $amount = max(0, $value);
        while ($amount >= 1024 && $index < count($units) - 1) {
            $amount /= 1024;
            $index++;
        }

        return number_format($amount, $index === 0 ? 0 : 2, '.', '') . ' ' . $units[$index];
    }
}
