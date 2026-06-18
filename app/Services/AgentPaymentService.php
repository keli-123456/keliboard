<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\AgentDomain;
use App\Models\AgentProfile;
use App\Models\Payment;
use App\Models\User;
use App\Services\Plugin\PluginManager;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;

class AgentPaymentService
{
    public function availableMethods(): array
    {
        app(PluginManager::class)->initializeEnabledPlugins();
        $methods = (new PaymentService('temp'))->getAvailablePaymentMethods();

        return collect($methods)
            ->map(fn (array $method, string $key): array => [
                'payment' => $key,
                'name' => (string) ($method['name'] ?? $key),
                'icon' => $method['icon'] ?? null,
                'type' => $method['type'] ?? 'plugin',
            ])
            ->values()
            ->all();
    }

    public function list(User $agent): array
    {
        $this->activeProfile($agent);

        return Payment::query()
            ->where('owner_type', Payment::OWNER_AGENT)
            ->where('owner_id', $agent->id)
            ->orderBy('sort')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Payment $payment): array => $this->paymentPayload($payment))
            ->values()
            ->all();
    }

    public function form(User $agent, string $payment, ?int $paymentId = null): array
    {
        $this->activeProfile($agent);
        $payment = $this->normalizePaymentName($payment);
        if ($paymentId !== null) {
            $row = Payment::query()->find($paymentId);
            if (!$row) {
                throw new ApiException('Payment method does not exist');
            }
            $this->assertOwnedByAgent($row, (int) $agent->id);
            $payment = (string) $row->payment;
        }
        $this->assertPaymentPluginEnabled($payment);

        return (new PaymentService($payment, $paymentId))->form();
    }

    public function save(User $agent, array $payload): Payment
    {
        $paymentName = $this->normalizePaymentName((string) ($payload['payment'] ?? ''));
        $this->assertPaymentPluginEnabled($paymentName);

        $id = (int) ($payload['id'] ?? 0);

        return DB::transaction(function () use ($agent, $payload, $paymentName, $id): Payment {
            $this->activeProfile($agent, true);

            $payment = $id > 0
                ? Payment::query()->where('id', $id)->lockForUpdate()->first()
                : new Payment();
            if (!$payment) {
                throw new ApiException('Payment method does not exist');
            }
            if ($payment->exists) {
                $this->assertOwnedByAgent($payment, (int) $agent->id);
            }
            $ownerDomainId = $this->ownerDomainId($agent, $payload['owner_domain_id'] ?? null, true);

            $payment->owner_type = Payment::OWNER_AGENT;
            $payment->owner_id = (int) $agent->id;
            $payment->owner_domain_id = $ownerDomainId;
            $payment->payment = $paymentName;
            $payment->name = trim((string) ($payload['name'] ?? $paymentName));
            $payment->icon = $payload['icon'] ?? null;
            $payment->config = $this->mergedConfig($payment, (array) ($payload['config'] ?? []));
            $payment->notify_domain = $payload['notify_domain'] ?? null;
            $payment->handling_fee_fixed = isset($payload['handling_fee_fixed']) && $payload['handling_fee_fixed'] !== ''
                ? (int) $payload['handling_fee_fixed']
                : null;
            $payment->handling_fee_percent = isset($payload['handling_fee_percent']) && $payload['handling_fee_percent'] !== ''
                ? (float) $payload['handling_fee_percent']
                : null;
            $payment->enable = (bool) ($payload['enable'] ?? $payment->enable ?? false);
            $payment->sort = isset($payload['sort']) && $payload['sort'] !== ''
                ? (int) $payload['sort']
                : ($payment->sort ?? 0);
            if (!$payment->exists) {
                $payment->uuid = Helper::randomChar(8);
                $payment->created_at = time();
            }
            $payment->updated_at = time();
            $payment->save();

            return $payment->fresh() ?: $payment;
        });
    }

    public function toggle(User $agent, int $id): Payment
    {
        return DB::transaction(function () use ($agent, $id): Payment {
            $this->activeProfile($agent);
            $payment = Payment::query()->where('id', $id)->lockForUpdate()->first();
            if (!$payment) {
                throw new ApiException('Payment method does not exist');
            }
            $this->assertOwnedByAgent($payment, (int) $agent->id);

            $enabled = !(bool) $payment->enable;
            if ($enabled && $payment->owner_domain_id !== null) {
                $this->ownerDomainId($agent, $payment->owner_domain_id, true);
            }

            $payment->enable = $enabled;
            $payment->updated_at = time();
            $payment->save();

            return $payment->fresh() ?: $payment;
        });
    }

    public function delete(User $agent, int $id): bool
    {
        $this->activeProfile($agent);
        $payment = Payment::query()->find($id);
        if (!$payment) {
            throw new ApiException('Payment method does not exist');
        }
        $this->assertOwnedByAgent($payment, (int) $agent->id);

        return (bool) $payment->delete();
    }

    public function assertOwnedByAgent(Payment $payment, int $agentUserId): void
    {
        if (
            $payment->owner_type !== Payment::OWNER_AGENT
            || (int) $payment->owner_id !== $agentUserId
        ) {
            throw new ApiException('Payment method is unavailable');
        }
    }

    private function activeProfile(User $agent, bool $lock = false): AgentProfile
    {
        $query = AgentProfile::query()
            ->where('user_id', $agent->id)
            ->where('status', AgentCenterService::STATUS_ACTIVE);

        if ($lock) {
            $query->lockForUpdate();
        }

        $profile = $query->first();
        if (!$profile) {
            throw new ApiException('Agent permission is not active');
        }

        return $profile;
    }

    private function ownerDomainId(User $agent, mixed $ownerDomainId, bool $lock = false): ?int
    {
        if ($ownerDomainId === null || $ownerDomainId === '') {
            return null;
        }

        $domainId = (int) $ownerDomainId;
        $query = AgentDomain::query()->where('id', $domainId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $domain = $query->first();

        if (
            !$domain
            || (int) $domain->agent_user_id !== (int) $agent->id
            || (string) $domain->status !== AgentDomain::STATUS_ACTIVE
        ) {
            throw new ApiException('Domain is unavailable');
        }

        return $domainId;
    }

    private function assertPaymentPluginEnabled(string $payment): void
    {
        if ($payment === '') {
            throw new ApiException('Payment plugin is not enabled');
        }

        $enabled = collect($this->availableMethods())
            ->contains(fn (array $method): bool => $method['payment'] === $payment);
        if (!$enabled) {
            throw new ApiException('Payment plugin is not enabled');
        }
    }

    private function normalizePaymentName(string $payment): string
    {
        return trim($payment);
    }

    private function mergedConfig(Payment $payment, array $config): array
    {
        if (!$payment->exists) {
            return $config;
        }

        return array_replace((array) ($payment->config ?? []), $config);
    }

    private function paymentPayload(Payment $payment): array
    {
        return [
            'id' => (int) $payment->id,
            'owner_type' => (string) $payment->owner_type,
            'owner_id' => $payment->owner_id !== null ? (int) $payment->owner_id : null,
            'owner_domain_id' => $payment->owner_domain_id !== null ? (int) $payment->owner_domain_id : null,
            'uuid' => (string) $payment->uuid,
            'payment' => (string) $payment->payment,
            'name' => (string) $payment->name,
            'icon' => $payment->icon,
            'notify_domain' => $payment->notify_domain,
            'notify_url' => $this->notifyUrl($payment),
            'handling_fee_fixed' => $payment->handling_fee_fixed !== null ? (int) $payment->handling_fee_fixed : null,
            'handling_fee_percent' => $payment->handling_fee_percent !== null ? (float) $payment->handling_fee_percent : null,
            'enable' => (bool) $payment->enable,
            'sort' => $payment->sort !== null ? (int) $payment->sort : null,
            'created_at' => $payment->created_at ? (int) $payment->created_at : null,
            'updated_at' => $payment->updated_at ? (int) $payment->updated_at : null,
        ];
    }

    private function notifyUrl(Payment $payment): string
    {
        $notifyUrl = url("/api/v1/guest/payment/notify/{$payment->payment}/{$payment->uuid}");
        if ($payment->notify_domain) {
            $parseUrl = parse_url($notifyUrl);
            $notifyUrl = rtrim((string) $payment->notify_domain, '/') . ($parseUrl['path'] ?? '');
        }

        return $notifyUrl;
    }
}
