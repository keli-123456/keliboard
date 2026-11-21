<?php

declare(strict_types=1);

namespace App\Services\ServerMachine;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class MachineRiskSettings
{
    public const OVERLAP_ACTIONS = ['review', 'reset_uuid', 'reset_subscription', 'reset_both'];
    public const OVERLAP_DEFAULTS = ['window_minutes' => 5, 'history_days' => 7, 'minimum_machines' => 3, 'repeat_minimum' => 3, 'action' => 'review'];
    public const DEFAULTS = ['enabled' => false, 'auto_ai' => false, 'notify_admin' => false, 'notify_followup' => false,
        'hourly_budget' => 2, 'overlap' => self::OVERLAP_DEFAULTS];
    public const CACHE_KEY = 'machine_risk:policy:v3';

    public function available(): bool
    {
        return Schema::hasTable('v2_machine_risk_policy') && Schema::hasTable('v2_machine_risk_event') && Schema::hasTable('v2_machine_risk_ai_attempt')
            && Schema::hasTable('v2_machine_risk_investigation');
    }

    public function snapshot(): array
    {
        return Cache::remember(self::CACHE_KEY, 30, function (): array {
            if (!$this->available()) return ['available' => false, 'revision' => 0, 'settings' => self::DEFAULTS];
            $row = DB::table('v2_machine_risk_policy')->where('id', 1)->first();
            return ['available' => true, 'revision' => (int) ($row->revision ?? 1), 'settings' => self::effective($row?->config)];
        });
    }

    public static function effective(?string $config): array
    {
        $settings = array_replace(self::DEFAULTS, array_intersect_key((array) json_decode($config ?? '', true), self::DEFAULTS));
        if (!is_array($settings['overlap']) || !self::validOverlap($settings['overlap'])) $settings['overlap'] = self::OVERLAP_DEFAULTS;
        if ($settings['enabled'] !== true) $settings['overlap']['action'] = 'review';
        return $settings;
    }

    private static function validOverlap(array $overlap): bool
    {
        if (array_diff_key(self::OVERLAP_DEFAULTS, $overlap) || array_diff_key($overlap, self::OVERLAP_DEFAULTS)
            || !in_array($overlap['action'], self::OVERLAP_ACTIONS, true)) return false;
        foreach (['window_minutes' => [1, 30], 'history_days' => [1, 7], 'minimum_machines' => [3, 10], 'repeat_minimum' => [2, 10]] as $key => [$min, $max]) {
            if (!is_int($overlap[$key]) || $overlap[$key] < $min || $overlap[$key] > $max) return false;
        }
        return true;
    }

    public function settings(): array
    {
        return $this->snapshot()['settings'];
    }

    public function enabled(): bool
    {
        try { return $this->settings()['enabled'] === true; }
        catch (\Throwable) { return false; }
    }

    public function save(array $settings, int $revision, int $adminId, bool $confirmed, bool $overlapConfirmed = false): array
    {
        if (!$this->available()) throw new RuntimeException('migration_required');
        if (array_diff(array_keys(array_diff_key(self::DEFAULTS, ['notify_followup' => true, 'overlap' => true])), array_keys($settings))
            || !is_bool($settings['enabled']) || !is_bool($settings['auto_ai']) || !is_bool($settings['notify_admin'])
            || (array_key_exists('notify_followup', $settings) && !is_bool($settings['notify_followup']))
            || !is_int($settings['hourly_budget']) || $settings['hourly_budget'] < 1 || $settings['hourly_budget'] > 10
            || (array_key_exists('overlap', $settings) && (!is_array($settings['overlap']) || !self::validOverlap($settings['overlap'])))) {
            throw new RuntimeException('invalid_policy');
        }
        if ($settings['enabled'] && !$confirmed) throw new RuntimeException('confirmation_required');
        if ($settings['enabled'] && isset($settings['overlap']) && $settings['overlap']['action'] !== 'review') {
            if (!$overlapConfirmed) throw new RuntimeException('rotation_confirmation_required');
            if (!MachineRiskCredentialActions::ready($settings['overlap']['action'])) throw new RuntimeException('rotation_storage_unavailable');
        }
        DB::transaction(function () use ($settings, $revision, $adminId): void {
            $row = DB::table('v2_machine_risk_policy')->where('id', 1)->lockForUpdate()->first();
            if (!$row || (int) $row->revision !== $revision) throw new RuntimeException('policy_changed');
            if (!array_key_exists('notify_followup', $settings)) $settings['notify_followup'] = (json_decode($row->config, true)['notify_followup'] ?? false) === true;
            $settings['overlap'] ??= self::effective($row->config)['overlap'];
            if (!$settings['enabled']) $settings = array_replace($settings, ['auto_ai' => false, 'notify_admin' => false, 'notify_followup' => false]);
            if (!$settings['enabled']) $settings['overlap']['action'] = 'review';
            if (!$settings['notify_admin']) $settings['notify_followup'] = false;
            DB::table('v2_machine_risk_policy')->where('id', 1)->update(['revision' => $revision + 1,
                'config' => json_encode(array_intersect_key($settings, self::DEFAULTS), JSON_THROW_ON_ERROR),
                'updated_by' => $adminId, 'updated_at' => time()]);
        });
        Cache::forget(self::CACHE_KEY);
        Cache::forget('machine_risk:policy:v2');
        Cache::forget('machine_risk:policy');
        return ['revision' => $revision + 1, 'settings' => $this->settings()];
    }
}
