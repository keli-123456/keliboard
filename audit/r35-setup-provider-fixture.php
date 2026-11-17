<?php
declare(strict_types=1);

// CI-only HTTP boundary; no bootstrap, setup, DNS or stage receipt is fabricated.
return static function (string $directory): Closure {
    if (getenv('GITHUB_ACTIONS') !== 'true' || PHP_OS_FAMILY !== 'Linux' || PHP_SAPI !== 'cli'
        || posix_geteuid() !== 0 || realpath($directory) !== $directory || (fileperms($directory) & 0777) !== 0700)
        throw new RuntimeException('fixture_provider_scope');
    $cloud = json_decode(file_get_contents($directory . '/fixture-cloud.json'), true, 32, JSON_THROW_ON_ERROR);
    $row = $cloud['row'];
    $dnsPath = $directory . '/fixture-dns.json';
    if (!is_file($dnsPath) || is_link($dnsPath) || (fileperms($dnsPath) & 0777) !== 0600)
        throw new RuntimeException('fixture_dns_file');
    Illuminate\Support\Facades\Http::preventStrayRequests();
    return static function ($request) use ($row, $dnsPath) {
        $response = static fn ($value, $status = 200) => Illuminate\Support\Facades\Http::response($value, $status);
        if ($request->url() === 'https://alidns.aliyuncs.com/') {
            if ($request->method() !== 'POST' || !is_string($request['Signature'])) throw new RuntimeException('fixture_dns_signature');
            $state = json_decode(file_get_contents($dnsPath), true, 32, JSON_THROW_ON_ERROR);
            $name = 'control-' . Keli\Acceptance\MemberProviderBindings::RUN;
            $action = $request['Action'];
            $id = '6001';
            if ($action === 'DescribeSubDomainRecords') {
                if ($request['SubDomain'] !== $name . '.dnscloudcloud.top') throw new RuntimeException('fixture_dns_name');
                return $response(['TotalCount' => count($state['rows']), 'PageNumber' => 1, 'PageSize' => 50,
                    'DomainRecords' => ['Record' => array_values($state['rows'])]]);
            }
            if ($action === 'DescribeDomainRecordInfo') {
                if ($request['RecordId'] !== $id) throw new RuntimeException('fixture_dns_id');
                return isset($state['rows'][$id]) ? $response($state['rows'][$id]) : $response(['Code' => 'InvalidRR.NoExist'], 400);
            }
            if ($action === 'AddDomainRecord') {
                if ($state['create'] !== 0 || $state['rows'] !== [] || $request['DomainName'] !== 'dnscloudcloud.top'
                    || $request['RR'] !== $name || $request['Type'] !== 'A' || $request['Line'] !== 'default'
                    || (int) $request['TTL'] !== 600 || $request['Value'] !== $row['ipv4'][0])
                    throw new RuntimeException('fixture_dns_create_scope');
                $state['rows'][$id] = ['RecordId' => $id, 'DomainName' => 'dnscloudcloud.top', 'RR' => $name,
                    'Type' => 'A', 'Line' => 'default', 'TTL' => 600, 'Value' => $request['Value'], 'Remark' => '', 'Status' => 'ENABLE'];
                $state['create']++;
            } elseif ($request['RecordId'] === $id && isset($state['rows'][$id])) {
                if ($action === 'UpdateDomainRecordRemark' && $state['claim'] === 0
                    && preg_match('/^keli-set-[a-f0-9]{48}$/D', $request['Remark'])) {
                    $state['rows'][$id]['Remark'] = $request['Remark']; $state['claim']++;
                } elseif ($action === 'DeleteDomainRecord' && $state['delete'] === 0) {
                    unset($state['rows'][$id]); $state['delete']++;
                } else throw new RuntimeException('fixture_dns_mutation_scope');
            } else throw new RuntimeException('fixture_dns_operation');
            file_put_contents($dnsPath, json_encode($state, JSON_THROW_ON_ERROR)); chmod($dnsPath, 0600);
            return $response(['RecordId' => $id, 'RequestId' => 'fixture-only']);
        }
        if ($request->method() !== 'GET' || parse_url($request->url(), PHP_URL_HOST) !== 'api.linode.com')
            throw new RuntimeException('fixture_provider_request');
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v4/linode/instances/' . $row['id']) return $response($row);
        if ($path === '/v4/account') return $response(['euuid' => 'fixture-account-0001']);
        $rows = match ($path) {
            '/v4/regions' => [['id' => 'sg-sin-2', 'label' => 'Singapore', 'status' => 'ok', 'site_type' => 'core', 'capabilities' => ['Linodes', 'Metadata']]],
            '/v4/linode/types' => [['id' => 'g6-standard-4', 'class' => 'standard', 'vcpus' => 4, 'memory' => 8192,
                'disk' => 163840, 'price' => ['monthly' => 48]]],
            '/v4/images' => [['id' => 'linode/debian12', 'label' => 'Debian 12', 'is_public' => true, 'deprecated' => false,
                'status' => 'available', 'capabilities' => ['cloud-init'], 'min_disk_size' => 4]],
            default => throw new RuntimeException('fixture_provider_operation'),
        };
        return $response(['data' => $rows, 'page' => 1, 'pages' => 1, 'results' => count($rows)]);
    };
};
