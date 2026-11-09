<?php
namespace App\Services\ServerMachine\Cloud;

use App\Models\MachineCloudAccount;

trait CloudCatalogReads
{
    public function catalog(MachineCloudAccount $account): array
    {
        $deadline = microtime(true) + 20; $provider = $account->provider;
        $paths = match ($provider) {
            'linode' => [['/regions', 'data'], ['/linode/types', 'data'], ['/images', 'data']],
            'digitalocean' => [['/regions', 'regions'], ['/sizes', 'sizes'], ['/images', 'images']],
            'vultr' => [['/regions', 'regions'], ['/plans', 'plans'], ['/os', 'os']],
            default => throw new CloudException('unsupported_provider'),
        };
        $collections = [];
        foreach ($paths as [$path, $key]) $collections[] = $this->catalogPages($account, $path, $key, $deadline);
        [$regionRows, $sizeRows, $imageRows] = $collections;
        $regions = []; $sizes = []; $images = [];
        foreach ($regionRows as $row) {
            if (!is_array($row)) throw new CloudException('provider_invalid');
            if (isset($row['capabilities']) && !is_array($row['capabilities'])) throw new CloudException('provider_invalid');
            if ($provider === 'linode' && (($row['status'] ?? null) !== 'ok' || ($row['site_type'] ?? null) !== 'core'
                || !in_array('Linodes', $row['capabilities'] ?? [], true) || !in_array('Metadata', $row['capabilities'] ?? [], true))) continue;
            if ($provider === 'digitalocean' && ($row['available'] ?? null) !== true) continue;
            $id = $this->catalogId($row[$provider === 'digitalocean' ? 'slug' : 'id'] ?? null);
            if (isset($regions[$id])) throw new CloudException('catalog_incomplete');
            $regions[$id] = ['id' => $id, 'label' => $this->catalogLabel($row['label'] ?? $row['name'] ?? $row['city'] ?? $id)];
        }
        $ids = array_map('strval', array_keys($regions));
        foreach ($sizeRows as $row) {
            if (!is_array($row)) throw new CloudException('provider_invalid');
            if ($provider === 'linode' && ($row['class'] ?? null) !== 'standard') continue;
            if ($provider === 'digitalocean' && ($row['available'] ?? null) !== true) continue;
            if ($provider === 'vultr' && !in_array($row['type'] ?? null, ['vc2', 'vhf', 'vdc'], true)) continue;
            $id = $this->catalogId($row[$provider === 'digitalocean' ? 'slug' : 'id'] ?? null);
            // Standard Linodes use core regions; the premium/GPU availability feed is not a complete standard-plan catalog.
            $locations = $provider === 'linode' ? $ids : ($row[$provider === 'digitalocean' ? 'regions' : 'locations'] ?? []);
            if (!is_array($locations) || !array_is_list($locations) || count($locations) > 500) throw new CloudException('provider_invalid');
            $locations = array_values(array_intersect($ids, array_map(fn ($location) => $this->catalogId($location), $locations)));
            if (!$locations) continue;
            $disk = $this->catalogNumber($row['disk'] ?? null);
            if (isset($sizes[$id])) throw new CloudException('catalog_incomplete');
            $sizes[$id] = ['id' => $id, 'label' => $id, 'regions' => $locations,
                'cpu' => $this->catalogNumber($row['vcpus'] ?? $row['vcpu_count'] ?? null),
                'memory_mib' => $this->catalogNumber($row['memory'] ?? $row['ram'] ?? null),
                'disk_gib' => $provider === 'linode' ? $disk / 1024 : $disk,
                'monthly_usd' => $this->catalogNumber($row['price']['monthly'] ?? $row['price_monthly'] ?? $row['monthly_cost'] ?? null, true)];
        }
        foreach ($imageRows as $row) {
            if (!is_array($row)) throw new CloudException('provider_invalid');
            if (isset($row['capabilities']) && !is_array($row['capabilities'])) throw new CloudException('provider_invalid');
            if ($provider === 'linode' && (($row['is_public'] ?? null) !== true || ($row['deprecated'] ?? true) !== false
                || ($row['status'] ?? null) !== 'available' || !in_array('cloud-init', $row['capabilities'] ?? [], true))) continue;
            if ($provider === 'digitalocean' && (($row['public'] ?? null) !== true || ($row['status'] ?? null) !== 'available'
                || ($row['type'] ?? null) !== 'base' || !is_string($row['slug'] ?? null) || !str_ends_with($row['slug'], '-x64'))) continue;
            if ($provider === 'vultr' && (($row['arch'] ?? null) !== 'x64' || !in_array($row['family'] ?? null, ['debian', 'ubuntu', 'almalinux', 'rockylinux', 'centos', 'fedora'], true))) continue;
            $id = $this->catalogId($row[$provider === 'digitalocean' ? 'slug' : 'id'] ?? null, $provider === 'linode');
            $locations = $provider === 'digitalocean' ? ($row['regions'] ?? []) : $ids;
            if (!is_array($locations) || !array_is_list($locations) || count($locations) > 500) throw new CloudException('provider_invalid');
            if (isset($images[$id])) throw new CloudException('catalog_incomplete');
            $images[$id] = ['id' => $id, 'label' => $this->catalogLabel($row['label'] ?? $row['name'] ?? $id),
                'regions' => array_values(array_intersect($ids, array_map(fn ($location) => $this->catalogId($location), $locations))), 'min_disk_gib' => $this->catalogNumber($row['min_disk_size'] ?? 0)];
        }
        if (!$regions || !$sizes || !$images) throw new CloudException('catalog_empty');
        return ['regions' => array_values($regions), 'sizes' => array_values($sizes), 'images' => array_values($images)];
    }

    protected function catalogPages(MachineCloudAccount $account, string $path, string $key, float $deadline): array
    {
        $rows = []; $cursor = null; $seen = []; $expected = null;
        for ($page = 1; $page <= 3; $page++) {
            if (microtime(true) > $deadline) throw new CloudException('catalog_incomplete');
            $query = $account->provider === 'linode' ? ['page' => $page, 'page_size' => 100] : ['per_page' => 100];
            if ($account->provider === 'digitalocean') $query['page'] = $page;
            if ($cursor !== null) $query['cursor'] = $cursor;
            if ($path === '/images' && $account->provider === 'digitalocean') $query['type'] = 'distribution';
            $data = $account->provider === 'linode' ? $this->request($account, 'GET', $path, $query, $path === '/images' ? ['is_public' => true] : [])
                : $this->request($account, 'GET', $path, $query);
            if (!is_array($data[$key] ?? null) || !array_is_list($data[$key]) || count($data[$key]) > 100) throw new CloudException('provider_invalid');
            $rows = array_merge($rows, $data[$key]);
            if ($account->provider === 'linode') {
                if (!is_int($data['pages'] ?? null) || $data['pages'] < 0 || $data['pages'] > 3) throw new CloudException('catalog_incomplete');
                if ($expected !== null && $expected !== $data['pages']) throw new CloudException('catalog_incomplete');
                $expected = $data['pages'];
                if (isset($data['page']) && $data['page'] !== $page) throw new CloudException('catalog_incomplete');
                if ($page >= $data['pages']) {
                    if (isset($data['results']) && $data['results'] !== count($rows)) throw new CloudException('catalog_incomplete');
                    return $rows;
                }
                if (!$data[$key]) throw new CloudException('catalog_incomplete');
            } else {
                $total = $data['meta']['total'] ?? null;
                if (!is_int($total) || $total < 0 || $total > 300) throw new CloudException('catalog_incomplete');
                if (($expected !== null && $expected !== $total) || count($rows) > $total) throw new CloudException('catalog_incomplete');
                $expected = $total;
                if (count($rows) === $total) {
                    $next = $account->provider === 'vultr' ? ($data['meta']['links']['next'] ?? null) : ($data['links']['pages']['next'] ?? null);
                    if ($next !== null && $next !== '') throw new CloudException('catalog_incomplete');
                    return $rows;
                }
                if (!$data[$key]) throw new CloudException('catalog_incomplete');
                if ($account->provider === 'vultr') {
                    $cursor = $data['meta']['links']['next'] ?? null;
                    if (!is_string($cursor) || !preg_match('~^[A-Za-z0-9+/_=-]{1,2048}$~D', $cursor) || isset($seen[$cursor])) throw new CloudException('catalog_incomplete');
                    $seen[$cursor] = true;
                }
            }
        }
        throw new CloudException('catalog_incomplete');
    }

    private function catalogId(mixed $id, bool $image = false): string
    {
        if (is_int($id)) $id = (string) $id;
        if (!is_string($id) || !preg_match($image ? '~^linode/[a-z0-9-]{1,60}$~D' : '/^[a-z0-9-]{1,80}$/D', $id)) throw new CloudException('provider_invalid');
        return $id;
    }
    private function catalogLabel(mixed $label): string
    {
        if (!is_string($label) || preg_match('/[\x00-\x1F\x7F]/', $label)) throw new CloudException('provider_invalid');
        return mb_substr($label, 0, 100);
    }
    private function catalogNumber(mixed $value, bool $nullable = false): ?float
    {
        if ($nullable && $value === null) return null;
        if (!is_numeric($value) || !is_finite((float) $value) || $value < 0 || $value > 100000000) throw new CloudException('provider_invalid');
        return (float) $value;
    }
}
