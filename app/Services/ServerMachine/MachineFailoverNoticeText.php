<?php
namespace App\Services\ServerMachine;

use App\Models\MachineOperationNotice;

final class MachineFailoverNoticeText
{
    private const STAGES = ['ready' => '等待编排', 'creating' => '等待云机创建', 'retry_ready' => '等待安全重试判定',
        'queued' => '已预留创建名额', 'dispatching' => '等待云端创建结果', 'waiting_provider' => '等待云实例运行',
        'waiting_initialization' => '等待 kelinode-rs 安装回连', 'waiting_agent' => '等待 Agent 上报',
        'waiting_node_reports' => '等待节点上报', 'waiting_ipv4_probe' => '等待国内 IPv4 可达证据',
        'awaiting_acceptance' => '等待隔离 TCP/UDP 验收', 'outcome_unknown' => '创建结果未知，只核对原请求',
        'reconciling_create' => '仅核对原创建意图', 'awaiting_dns' => '等待独立 DNS 切换', 'reconciling_dns' => '仅核对原 DNS 意图',
        'needs_review' => '待核查', 'retained' => '资源保留', 'cancelled' => '任务取消', 'completed' => 'DNS 回读已核验'];
    private const REASONS = ['deadline' => '核验期限已过', 'attempt_budget' => '本轮尝试次数不足',
        'shared_monthly_budget' => '共享月费预算不足', 'monthly_budget' => '月费预算不足',
        'cost_baseline_required' => '成本基线尚未核验', 'receipt_unverified' => '回执缺失、已过期或身份不匹配',
        'credential_group_exhausted' => '凭证组没有可用成员', 'original_intent_only' => '结果未确认，只允许核对原意图',
        'native_context_changed' => '编排配置已变化', 'native_lineage_changed' => '任务衔接身份已变化',
        'native_intent_expired' => '编排意图已过期', 'review_claimed' => '候选资源已认领，仍需复核',
        'review_hold' => '已转入人工核查', 'unknown' => '请核对面板中的本地证据'];
    private const PHASES = ['progress' => '原生故障转移进度', 'timeout' => '原生任务超时', 'review' => '原生结果待核查',
        'budget' => '原生预算受阻', 'retained' => '原生资源保留', 'retained_risk' => '保留资源可能持续计费',
        'review_closed' => '原生复核已结案', 'probe_recovered' => '原实例 IPv4 探测恢复',
        'forwarding_verified' => '隔离转发回执已核验', 'completed' => '原生 DNS 回读已核验', 'cancelled' => '原生任务已取消'];

    public static function stage(string $stage): string { return isset(self::STAGES[$stage]) ? $stage : 'unknown'; }
    public static function reason(string $reason): string
    {
        return isset(self::REASONS[$reason]) ? $reason : MachineOperationNoticeText::reason($reason);
    }
    public static function message(MachineOperationNotice $notice): string
    {
        $s = $notice->snapshot;
        $lines = ['[' . (self::PHASES[$notice->phase] ?? self::PHASES['review']) . '] 通知 #' . $notice->id,
            '服务 #' . (int) ($s['service_id'] ?? 0) . ' / 运行 ' . (preg_match('/^[a-f0-9]{8}$/D', $s['run'] ?? '') ? $s['run'] : '-'),
            '成员 #' . (int) ($s['member_id'] ?? 0) . ' / 机器 #' . (int) ($s['machine_id'] ?? 0) . ' / 策略 #' . (int) ($s['policy_id'] ?? 0),
            '阶段：' . (self::STAGES[$s['result'] ?? ''] ?? '请查看面板核验状态')];
        if (!empty($s['attempt'])) $lines[] = '尝试序号：' . (int) $s['attempt'];
        $reason = self::reason((string) ($s['reason'] ?? ''));
        $lines[] = '原因：' . (self::REASONS[$reason] ?? MachineOperationNoticeText::description($reason));
        if ($notice->phase === 'retained_risk') $lines[] = '可能保留的资源：' . (int) ($s['retained_count'] ?? 0) . ' 个；本地记录不能证明当前账单，需只读核查云端，不自动删除。';
        if ($notice->phase === 'review_closed') $lines[] = '结案范围：' . (['retained' => '资源仍保留', 'released' => '本地预算已释放', 'no_intent' => '没有创建意图'][$s['resolution'] ?? ''] ?? '请查看复核记录') . '；不代表业务恢复。';
        if (in_array($notice->phase, ['forwarding_verified', 'completed'], true)) $lines[] = '隔离验收记录时间：' . self::time((int) ($s['checked_at'] ?? 0)) . ' / 当时回执有效至：' . self::time((int) ($s['valid_until'] ?? 0));
        $lines[] = match ($notice->phase) {
            'probe_recovered' => '仅确认原实例 IPv4 探测恢复，不代表客户端转发恢复，也未自动切换 DNS。',
            'forwarding_verified' => '仅核验同一候选实例的历史隔离 TCP/UDP 回执，不代表所有用户或当前实时业务正常。',
            'completed' => '仅核验原生任务的 DNS 服务商回读与隔离转发回执，不代表全球 DNS 传播或业务恢复。',
            default => '通知只读本地任务证据，不启动、重试、轮换或删除云机，不修改 DNS，也不更改执行开关。',
        };
        $lines[] = '记录时间：' . self::time((int) $notice->created_at) . ' (UTC+8)';
        return implode("\n", $lines);
    }
    private static function time(int $at): string { return $at > 0 ? \Carbon\CarbonImmutable::createFromTimestampUTC($at)->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') : '-'; }
}
