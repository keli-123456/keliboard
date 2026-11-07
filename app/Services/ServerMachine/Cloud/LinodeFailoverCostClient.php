<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount as Account;
use Illuminate\Support\Facades\Http;

// All requests are bounded GETs. Absence is trusted only for an unrestricted account view.
class LinodeFailoverCostClient extends LinodeClient
{
    public function authority(Account $account): bool
    {
        app(MachineCloudCredentialHealth::class)->assertAccountIdentity($account, $this->request($account, 'GET', '/account'));
        try {
            $response = Http::withToken($account->credentials['api_token'])->acceptJson()->withoutRedirecting()->connectTimeout(3)->timeout(5)
                ->get('https://api.linode.com/v4/profile/grants');
        } catch (\Throwable) { throw new CloudException('cost_read_failed'); }
        if ($response->status() === 204 && trim($response->body()) === '') return true;
        if ($response->status() === 200 && is_array($response->json())) return false;
        throw new CloudException('cost_scope_unknown');
    }

    public function completeInventory(Account $account): array
    {
        $rows = []; $expected = null;
        for ($page = 1; $page <= 3; $page++) {
            $data = $this->request($account, 'GET', '/linode/instances', ['page' => $page, 'page_size' => 100]);
            $this->pagination($data, $page);
            if ($expected !== null && $expected !== [$data['pages'], $data['results']]) throw new CloudException('cost_inventory_incomplete');
            $expected = [$data['pages'], $data['results']];
            foreach ($data['data'] as $raw) {
                $row = $this->normalize($raw); $id = $row['external_id'];
                if (isset($rows[$id]) || !is_array($raw['tags'] ?? null) || !is_bool($raw['backups']['enabled'] ?? null)) throw new CloudException('cost_inventory_incomplete');
                $rows[$id] = $row + ['tags' => $raw['tags'], 'backup_enabled' => $raw['backups']['enabled'], 'image' => $raw['image'] ?? null];
            }
            if ($page >= $data['pages']) {
                if (count($rows) !== $data['results']) throw new CloudException('cost_inventory_incomplete');
                return $rows;
            }
        }
        throw new CloudException('cost_inventory_incomplete');
    }

    public function quote(Account $account, array $row): int
    {
        if ($row['backup_enabled'] || count(array_filter($row['public_ips'], fn ($ip) => !str_contains($ip, ':'))) !== 1) throw new CloudException('cost_addons_unverified');
        $volumes = $this->request($account, 'GET', '/linode/instances/' . $row['external_id'] . '/volumes', ['page' => 1, 'page_size' => 100]);
        $this->pagination($volumes, 1);
        if ($volumes['results'] !== 0 || $volumes['data'] !== []) throw new CloudException('cost_addons_unverified');
        $type = $this->request($account, 'GET', '/linode/types/' . rawurlencode($row['size']));
        if (($type['id'] ?? null) !== $row['size'] || ($type['class'] ?? null) !== 'standard' || !is_array($type['region_prices'] ?? null)) throw new CloudException('cost_price_unverified');
        $price = $type['price']['monthly'] ?? null;
        $regions = array_filter($type['region_prices'], fn ($value) => is_array($value) && ($value['id'] ?? null) === $row['region']);
        if (count($regions) > 1) throw new CloudException('cost_price_unverified');
        if ($regions) $price = array_values($regions)[0]['monthly'] ?? null;
        if (!is_numeric($price) || !is_finite((float) $price) || $price < 0 || $price > 10000 || abs(round((float) $price * 100) - (float) $price * 100) > .000001) throw new CloudException('cost_price_unverified');
        return (int) round((float) $price * 100);
    }

    private function pagination(array $data, int $page): void
    {
        if (!is_array($data['data'] ?? null) || !array_is_list($data['data']) || count($data['data']) > 100
            || ($data['page'] ?? null) !== $page || !is_int($data['pages'] ?? null) || $data['pages'] < 0 || $data['pages'] > 3
            || !is_int($data['results'] ?? null) || $data['results'] < 0 || $data['results'] > 300
            || ($data['results'] === 0 ? !in_array($data['pages'], [0, 1], true) : $data['pages'] !== (int) ceil($data['results'] / 100))) throw new CloudException('cost_inventory_incomplete');
    }
}
