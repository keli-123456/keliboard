<?php


namespace App\Http\Resources;

use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlanResource extends JsonResource
{
    private const PRICE_MULTIPLIER = 100;
    private const RECURRING_PERIODS = [
        Plan::PERIOD_MONTHLY,
        Plan::PERIOD_QUARTERLY,
        Plan::PERIOD_HALF_YEARLY,
        Plan::PERIOD_YEARLY,
        Plan::PERIOD_TWO_YEARLY,
        Plan::PERIOD_THREE_YEARLY,
    ];

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $normalizedPrices = $this->getNormalizedPrices();
        $legacyPrices = $this->getPeriodPrices($normalizedPrices);
        $availablePeriods = $this->getAvailablePeriods($normalizedPrices);
        $recurringPeriods = $this->getRecurringPeriods($availablePeriods);

        return [
            'id' => $this->getResourceValue('id'),
            'group_id' => $this->getResourceValue('group_id'),
            'name' => $this->getResourceValue('name'),
            'tags' => $this->getResourceValue('tags'),
            'content' => $this->formatContent(),
            'upgrade_to_plan_ids' => $this->getUpgradeTargetPlanIds(),
            'prices' => $normalizedPrices,
            'available_periods' => $availablePeriods,
            'recurring_periods' => $recurringPeriods,
            'has_recurring_price' => !empty($recurringPeriods),
            'has_onetime_price' => array_key_exists(Plan::PERIOD_ONETIME, $normalizedPrices),
            'has_reset_price' => array_key_exists(Plan::PERIOD_RESET_TRAFFIC, $normalizedPrices),
            'site_context' => $this->getResourceValue('site_context'),
            'site_sale_periods' => $this->getResourceValue('site_sale_periods'),
            'agent_context' => $this->getResourceValue('agent_context'),
            'agent_sale_periods' => $this->getResourceValue('agent_sale_periods'),
            ...$legacyPrices,
            'capacity_limit' => $this->getFormattedCapacityLimit(),
            'transfer_enable' => $this->getResourceValue('transfer_enable'),
            'speed_limit' => $this->getResourceValue('speed_limit'),
            'device_limit' => $this->getResourceValue('device_limit'),
            'show' => (bool) $this->getResourceValue('show'),
            'sell' => (bool) $this->getResourceValue('sell'),
            'renew' => (bool) $this->getResourceValue('renew'),
            'reset_traffic_method' => $this->getResourceValue('reset_traffic_method'),
            'sort' => $this->getResourceValue('sort'),
            'created_at' => $this->getResourceValue('created_at'),
            'updated_at' => $this->getResourceValue('updated_at')
        ];
    }

    /**
     * Get normalized prices using modern period keys.
     *
     * @return array<string, int>
     */
    protected function getNormalizedPrices(): array
    {
        $rawPrices = $this->getResourceValue('prices', []);
        if (!is_array($rawPrices)) {
            return [];
        }

        return collect(Plan::LEGACY_PERIOD_MAPPING)
            ->mapWithKeys(function (string $newPeriod) use ($rawPrices): array {
                $price = $this->normalizePrice($rawPrices[$newPeriod] ?? null);
                return $price > 0 ? [$newPeriod => $price] : [];
            })
            ->all();
    }

    /**
     * Get transformed period prices using legacy period keys.
     *
     * @param array<string, int> $normalizedPrices
     * @return array<string, int|null>
     */
    protected function getPeriodPrices(array $normalizedPrices): array
    {
        return collect(Plan::LEGACY_PERIOD_MAPPING)
            ->mapWithKeys(function (string $newPeriod, string $legacyPeriod) use ($normalizedPrices): array {
                return [
                    $legacyPeriod => $normalizedPrices[$newPeriod] ?? null
                ];
            })
            ->all();
    }

    /**
     * Get active periods in legacy period format.
     *
     * @param array<string, int> $normalizedPrices
     * @return array<int, string>
     */
    protected function getAvailablePeriods(array $normalizedPrices): array
    {
        return collect(array_keys($normalizedPrices))
            ->map(fn(string $period): string => array_flip(Plan::LEGACY_PERIOD_MAPPING)[$period] ?? $period)
            ->values()
            ->all();
    }

    /**
     * Get recurring active periods in legacy period format.
     *
     * @param array<int, string> $availablePeriods
     * @return array<int, string>
     */
    protected function getRecurringPeriods(array $availablePeriods): array
    {
        $recurringLegacyPeriods = collect(self::RECURRING_PERIODS)
            ->map(fn(string $period): string => array_flip(Plan::LEGACY_PERIOD_MAPPING)[$period] ?? $period)
            ->all();

        return array_values(array_intersect($availablePeriods, $recurringLegacyPeriods));
    }

    /**
     * Get normalized upgrade target plan ids.
     *
     * @return array<int, int>
     */
    protected function getUpgradeTargetPlanIds(): array
    {
        $raw = $this->getResourceValue('upgrade_to_plan_ids', []);
        if (!is_array($raw)) {
            return [];
        }

        return collect($raw)
            ->map(fn(mixed $id): int => (int) $id)
            ->filter(fn(int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Get formatted capacity limit value
     *
     * @return int|string|null
     */
    protected function getFormattedCapacityLimit(): int|string|null
    {
        $limit = $this->getResourceValue('capacity_limit');

        return match (true) {
            $limit === null => null,
            $limit <= 0 => __('Sold out'),
            default => (int) $limit,
        };
    }

    /**
     * Format content with template variables
     *
     * @return string
     */
    protected function formatContent(): string
    {
        $content = (string) $this->getResourceValue('content', '');
        
        $replacements = [
            '{{transfer}}' => $this->getResourceValue('transfer_enable'),
            '{{speed}}' => $this->getResourceValue('speed_limit') === null ? __('No Limit') : $this->getResourceValue('speed_limit'),
            '{{devices}}' => $this->getResourceValue('device_limit') === null ? __('No Limit') : $this->getResourceValue('device_limit'),
            '{{reset_method}}' => $this->getResetMethodText(),
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $content
        );
    }

    /**
     * Get reset method text
     *
     * @return string
     */
    protected function getResetMethodText(): string
    {
        $method = $this->getResourceValue('reset_traffic_method');
        
        if ($method === Plan::RESET_TRAFFIC_FOLLOW_SYSTEM) {
            $method = admin_setting('reset_traffic_method', Plan::RESET_TRAFFIC_MONTHLY);
        }
        return match ($method) {
            Plan::RESET_TRAFFIC_FIRST_DAY_MONTH => __('First Day of Month'),
            Plan::RESET_TRAFFIC_MONTHLY => __('Monthly'),
            Plan::RESET_TRAFFIC_NEVER => __('Never'),
            Plan::RESET_TRAFFIC_FIRST_DAY_YEAR => __('First Day of Year'),
            Plan::RESET_TRAFFIC_YEARLY => __('Yearly'),
            default => __('Monthly')
        };
    }

    protected function getResourceValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->resource, $key, $default);
    }

    protected function normalizePrice(mixed $price): int
    {
        if ($price === null || $price === '') {
            return 0;
        }

        return max(0, (int) round(((float) $price) * self::PRICE_MULTIPLIER));
    }
}
