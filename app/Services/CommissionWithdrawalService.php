<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class CommissionWithdrawalService
{
    public const NETWORK_IDS = ['TRC20', 'ERC20', 'BEP20'];

    public function networks(bool $configuredOnly = false): array
    {
        $options = [
            ['id' => 'TRC20', 'name' => 'TRON (TRC20)', 'kind' => 'tron'],
            ['id' => 'ERC20', 'name' => 'Ethereum (ERC20)', 'kind' => 'evm'],
            ['id' => 'BEP20', 'name' => 'BNB Smart Chain (BEP20)', 'kind' => 'evm'],
        ];
        $enabled = admin_setting('commission_withdraw_usdt_networks', []);
        return array_values(array_filter($options, static fn (array $option): bool =>
            !$configuredOnly || (is_array($enabled) && in_array($option['id'], $enabled, true))
        ));
    }

    public function destination(array $data): array
    {
        $method = $data['withdraw_method'];
        $account = trim($data['withdraw_account']);
        $network = $data['withdraw_network'] ?? null;
        $option = null;
        if (preg_match('/(?:^|[^a-z0-9])usdt(?:$|[^a-z0-9])/i', $method)) {
            foreach ($this->networks(true) as $candidate) {
                if ($candidate['id'] === $network) $option = $candidate;
            }
            if (!$option) {
                throw ValidationException::withMessages(['withdraw_network' => __('Please select an enabled USDT withdrawal network.')]);
            }
            $pattern = $option['kind'] === 'tron' ? '/\AT[1-9A-HJ-NP-Za-km-z]{33}\z/' : '/\A0x[a-fA-F0-9]{40}\z/';
            if (!preg_match($pattern, $account)) {
                throw ValidationException::withMessages(['withdraw_account' => __('The address format does not match the selected withdrawal network.')]);
            }
        } elseif ($network !== null && $network !== '') {
            throw ValidationException::withMessages(['withdraw_network' => __('A network can only be selected for USDT withdrawals.')]);
        }
        return ['method' => $method, 'account' => $account, 'network' => $option['id'] ?? null, 'network_name' => $option['name'] ?? null];
    }

    public function ticketDetails(mixed $metadata, ?string $subject): ?array
    {
        if (is_array($metadata) && isset($metadata['method'], $metadata['account'])) {
            return $metadata + ['legacy' => false];
        }
        return app(StaffTicketVisibilityService::class)->isVisibleSubject($subject) ? null : ['legacy' => true];
    }
}
