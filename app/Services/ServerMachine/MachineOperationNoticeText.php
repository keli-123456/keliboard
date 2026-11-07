<?php

namespace App\Services\ServerMachine;

use App\Models\MachineOperationNotice;

final class MachineOperationNoticeText
{
    private const REASONS = [
        'source_unavailable' => '缺少新鲜的实例地址', 'cloud_recovery_pending' => '等待云实例恢复核验',
        'identity_changed' => '绑定身份已变化', 'record_changed' => '检测到外部修改，已暂停',
        'record_mismatch' => '解析记录不匹配，已暂停', 'record_locked' => '解析记录被锁定，已暂停',
        'credentials_required' => '缺少 DNS 凭证', 'provider_unavailable' => '服务商暂不可用',
        'provider_forbidden' => '服务商凭证权限不足', 'provider_rate_limited' => '服务商限流',
        'provider_rejected' => '服务商拒绝请求', 'verification_failed' => '服务商回读核验未通过',
        'sync_failed' => '同步未完成', 'deadline' => '超过任务核验期限', 'config_changed' => '配置或权限已变化',
        'queue_unavailable' => '任务未能排队', 'outcome_uncertain' => '下发结果未知，不重复下发',
        'permission_revoked' => '操作权限已撤销', 'state_changed' => '云端状态已变化',
        'provider_busy' => '云端有其他任务', 'external_shutdown' => '检测到关机事件',
        'maintenance' => '云平台维护中', 'safety_unknown' => '云端事件不完整',
        'resource_missing' => '云资源不存在', 'provider_invalid' => '云平台响应异常',
        'unknown' => '请查看面板核验状态', 'synced' => '服务商 API 回读已核验',
        'provisioning_disabled' => '创建开关已关闭', 'replacement_disabled' => '替换执行开关已关闭',
        'replacement_not_allowed' => '策略未获替换授权', 'replacement_evidence_changed' => '故障证据已变化',
        'replacement_guard_failed' => '替换前置检查未通过', 'replacement_cooldown' => '替换冷却中',
        'source_recovered' => '原实例 IPv4 探测已恢复', 'source_changed' => '机器身份已变化',
        'account_changed' => '云账号授权已变化', 'panel_origin_changed' => '面板地址已变化',
        'initialization_failed' => '新机初始化失败', 'reports_timeout' => '新机上报等待超时',
        'create_not_resolved' => '云端创建结果仍不确定', 'budget_exceeded' => '当前报价超过月费上限',
        'daily_budget' => '账号创建次数已达上限', 'cancelled_by_admin' => '管理员取消任务',
        'ownership_mismatch' => '实例归属核对不符', 'internal_failure' => '处理未完成，请核对任务',
    ];
    private const PROVISION_STATES = ['queued' => '已预留创建名额', 'creating' => '等待云机创建',
        'initializing' => '等待 kelinode-rs 安装', 'waiting_reports' => '等待新实例上报',
        'waiting_ipv4' => '等待新机 IPv4 探测', 'awaiting_acceptance' => '等待独立转发验收',
        'awaiting_dns' => '转发验收通过，等待 DNS 切换', 'cancelled' => '替换已取消',
        'needs_review' => '需要核对云端结果', 'outcome_unknown' => '创建结果未知，不重复创建',
        'failed' => '替换任务失败', 'completed' => '独立 DNS 切换回读已核验'];

    public static function reason(string $code): string { return isset(self::REASONS[$code]) ? $code : 'unknown'; }

    public static function message(MachineOperationNotice $notice): string
    {
        $s = $notice->snapshot;
        if ($notice->scope === 'provision') {
            $title = match ($notice->phase) { 'failed' => '替换任务失败', 'uncertain' => '替换结果待核对',
                'recovered' => '原实例 IPv4 探测恢复', 'completed' => '替换 DNS 回读已核验', default => '替换任务进度' };
            return implode("\n", ['[' . $title . '] 通知 #' . $notice->id,
                '机器 #' . (int) ($s['machine_id'] ?? 0) . ' / 策略 #' . (int) ($s['policy_id'] ?? 0),
                '替换任务 #' . $notice->source_id,
                '阶段：' . (self::PROVISION_STATES[$s['result'] ?? ''] ?? '请查看面板核验状态'),
                '原因：' . self::REASONS[self::reason((string) ($s['reason'] ?? ''))],
                $notice->phase === 'recovered' ? '仅确认原实例 IPv4 探测恢复；不代表客户端转发恢复，也未自动切换 DNS。'
                    : '仅说明替换任务阶段；保留原云机，不代表业务已恢复或 DNS 已全球传播。',
                '记录时间：' . \Carbon\CarbonImmutable::createFromTimestampUTC((int) $notice->created_at)->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') . ' (UTC+8)']);
        }
        $title = $notice->scope === 'dns'
            ? ($notice->phase === 'recovered' ? 'DNS 同步恢复' : 'DNS 同步受阻')
            : match ($notice->phase) { 'failed' => '云任务失败', 'uncertain' => '云任务结果待确认', 'recovered' => '云实例上报恢复已核验', default => '云任务完成' };
        $lines = ['[' . $title . '] 通知 #' . $notice->id,
            '机器 #' . (int) ($s['machine_id'] ?? 0) . ' / 实例 ' . (preg_match('/^[a-f0-9]{8}$/D', $s['installation'] ?? '') ? $s['installation'] : '未绑定')];
        if ($notice->scope === 'dns') {
            $host = is_string($s['hostname'] ?? null) && filter_var($s['hostname'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) ? $s['hostname'] : '-';
            $lines[] = '绑定 #' . $notice->source_id . ' / ' . (in_array($s['type'] ?? '', ['A', 'AAAA'], true) ? $s['type'] : '-') . ' / ' . $host;
            $lines[] = '状态：' . self::REASONS[self::reason((string) ($s['reason'] ?? ''))];
            if ($notice->phase === 'recovered') $lines[] = '已核验地址：' . (MachineEntryProbe::publicIp($s['ip'] ?? null) ?: '-');
            $lines[] = $notice->phase === 'recovered' ? '仅确认服务商回读，不代表各地 DNS 已传播。' : '同步受阻不等于域名不可访问；请以核验记录为准。';
        } else {
            $lines[] = '云任务 #' . $notice->source_id . ' / Linode #' . (preg_match('/^[0-9]{1,20}$/D', (string) ($s['cloud_id'] ?? '')) ? $s['cloud_id'] : '-');
            $lines[] = '操作：' . (['boot' => '开机', 'shutdown' => '关机', 'reboot' => '重启'][$s['action'] ?? ''] ?? '-');
            if (in_array($notice->phase, ['failed', 'uncertain'], true)) $lines[] = '原因：' . self::REASONS[self::reason((string) ($s['reason'] ?? ''))];
            $lines[] = $notice->phase === 'recovered' ? '云端运行和同一实例上报已核验，不代表客户端转发恢复。'
                : (($s['action'] ?? '') === 'shutdown' && $notice->phase === 'completed' ? '云端关机已确认；自动开机保持暂停。'
                    : '仅说明此电源任务的核验结果，不代表客户端转发成功。');
        }
        $lines[] = '记录时间：' . \Carbon\CarbonImmutable::createFromTimestampUTC((int) $notice->created_at)->setTimezone('Asia/Shanghai')->format('Y-m-d H:i:s') . ' (UTC+8)';
        return implode("\n", $lines);
    }
}
