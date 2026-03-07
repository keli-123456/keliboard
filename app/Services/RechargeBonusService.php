<?php

namespace App\Services;

class RechargeBonusService
{
    public function isEnabled(): bool
    {
        return (bool) admin_setting('recharge_bonus_enable', false);
    }

    public function getRules(): array
    {
        return $this->normalizeRules(admin_setting('recharge_bonus_rules', []));
    }

    public function getConfig(): array
    {
        return [
            'recharge_bonus_enable' => $this->isEnabled(),
            'recharge_bonus_rules' => $this->getRules(),
        ];
    }

    public function calculateBonus(int $amount): int
    {
        if ($amount <= 0 || !$this->isEnabled()) {
            return 0;
        }

        $matchedBonus = 0;
        foreach ($this->getRules() as $rule) {
            $thresholdAmount = $this->toAmountInCents($rule['amount'] ?? 0);
            $bonusAmount = $this->toAmountInCents($rule['bonus'] ?? 0);

            if ($thresholdAmount <= 0 || $bonusAmount <= 0) {
                continue;
            }

            if ($amount >= $thresholdAmount) {
                $matchedBonus = $bonusAmount;
            }
        }

        return $matchedBonus;
    }

    public function normalizeRules(mixed $rules): array
    {
        if (!is_array($rules)) {
            return [];
        }

        $normalized = [];

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $thresholdAmount = $this->toAmountInCents($rule['amount'] ?? null);
            $bonusAmount = $this->toAmountInCents($rule['bonus'] ?? null);

            if ($thresholdAmount <= 0 || $bonusAmount <= 0) {
                continue;
            }

            $normalized[$thresholdAmount] = [
                'amount' => round($thresholdAmount / 100, 2),
                'bonus' => round($bonusAmount / 100, 2),
            ];
        }

        ksort($normalized, SORT_NUMERIC);

        return array_values($normalized);
    }

    private function toAmountInCents(mixed $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        return max(0, (int) round(((float) $amount) * 100));
    }
}
