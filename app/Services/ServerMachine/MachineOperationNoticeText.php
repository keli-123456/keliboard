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
    ];

    public static function reason(string $code): string { return isset(self::REASONS[$code]) ? $code : 'unknown'; }

    public static function message(MachineOperationNotice $notice): string
    {
        $s = $notice->snapshot;
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
