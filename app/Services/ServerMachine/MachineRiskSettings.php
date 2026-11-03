<?php

declare(strict_types=1);

namespace App\Services\ServerMachine;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class MachineRiskSettings
{
    public const DEFAULTS = ['enabled' => false, 'auto_ai' => false, 'notify_admin' => false, 'hourly_budget' => 2];

    public function available(): bool
    {
        return Schema::hasTable('v2_machine_risk_policy') && Schema::hasTable('v2_machine_risk_event') && Schema::hasTable('v2_machine_risk_ai_attempt');
    }

    public function snapshot(): array
    {
        return Cache::remember('machine_risk:policy', 30, function (): array {
            if (!$this->available()) return ['available' => false, 'revision' => 0, 'settings' => self::DEFAULTS];
            $row = DB::table('v2_machine_risk_policy')->where('id', 1)->first();
            return ['available' => true, 'revision' => (int) ($row->revision ?? 1),
                'settings' => array_replace(self::DEFAULTS, array_intersect_key($row ? (array) json_decode($row->config, true) : [], self::DEFAULTS))];
        });
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

    public function save(array $settings, int $revision, int $adminId, bool $confirmed): array
    {
        if (!$this->available()) throw new RuntimeException('migration_required');
        if (array_diff(array_keys(self::DEFAULTS), array_keys($settings))
            || !is_bool($settings['enabled']) || !is_bool($settings['auto_ai']) || !is_bool($settings['notify_admin'])
            || !is_int($settings['hourly_budget']) || $settings['hourly_budget'] < 1 || $settings['hourly_budget'] > 10) {
            throw new RuntimeException('invalid_policy');
        }
        if ($settings['enabled'] && !$confirmed) throw new RuntimeException('confirmation_required');
        if (!$settings['enabled']) $settings = array_replace($settings, ['auto_ai' => false, 'notify_admin' => false]);
        DB::transaction(function () use ($settings, $revision, $adminId): void {
            $row = DB::table('v2_machine_risk_policy')->where('id', 1)->lockForUpdate()->first();
            if (!$row || (int) $row->revision !== $revision) throw new RuntimeException('policy_changed');
            DB::table('v2_machine_risk_policy')->where('id', 1)->update(['revision' => $revision + 1,
                'config' => json_encode(array_intersect_key($settings, self::DEFAULTS), JSON_THROW_ON_ERROR),
                'updated_by' => $adminId, 'updated_at' => time()]);
        });
        Cache::forget('machine_risk:policy');
        return ['revision' => $revision + 1, 'settings' => $this->settings()];
    }
}
