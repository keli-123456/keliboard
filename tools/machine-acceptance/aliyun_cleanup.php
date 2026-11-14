<?php
return static function (array $dns, callable $cleanupRemaining): Closure {
    // This code also runs on the read-only credential bridge, which may host an older application.
    $remark = static function (mixed $value): string {
        if (!is_string($value) || strlen($value) > 1000) throw new RuntimeException('dns_read_rejected');
        if (preg_match('/^keli-set-b64-([A-Za-z0-9_-]{32})$/D', $value, $match)) {
            $bytes = base64_decode(strtr($match[1], '-_', '+/'), true);
            if ($bytes !== false && strlen($bytes) === 24) return 'keli-set-' . bin2hex($bytes);
        }
        return $value;
    };
    return static function (string $action, array $data, mixed $allowMissing = false) use ($dns, $cleanupRemaining, $remark): ?array {
        if (!in_array($action, ['DescribeSubDomainRecords', 'DescribeDomainInfo', 'DescribeDomainRecordInfo'], true)) {
            throw new RuntimeException('dns_read_action_required');
        }
        if ($action === 'DescribeDomainRecordInfo' && (!is_string($data['RecordId'] ?? null)
            || !preg_match('/^[1-9][0-9]{0,39}$/D', $data['RecordId']))) {
            throw new RuntimeException('dns_record_id_invalid');
        }
        $cleanupRemaining();
        $parameters = App\Services\ServerMachine\Dns\DnsProviderClient::signAliyun(array_merge($data, [
            'Action' => $action, 'Format' => 'JSON', 'Version' => '2015-01-09', 'AccessKeyId' => $dns['access_key_id'],
            'SignatureMethod' => 'HMAC-SHA1', 'SignatureVersion' => '1.0', 'SignatureNonce' => bin2hex(random_bytes(16)),
            'Timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ]), $dns['access_key_secret']);
        $seconds = $cleanupRemaining();
        $response = Illuminate\Support\Facades\Http::asForm()->acceptJson()->withoutRedirecting()
            ->connectTimeout(min(4, $seconds))->timeout(min(10, $seconds))->post('https://alidns.aliyuncs.com/', $parameters);
        $cleanupRemaining();
        if (strlen($response->body()) > 262144) throw new RuntimeException('dns_read_failed');
        $json = $response->json();
        // A missing exact ID is evidence only; full inventories and ownership checks remain the caller's responsibility.
        if ($allowMissing === true && $action === 'DescribeDomainRecordInfo' && in_array($response->status(), [200, 400, 404], true)
            && is_array($json) && !array_key_exists('RecordId', $json)
            && in_array($json['Code'] ?? null, ['InvalidRecordId.NotFound', 'InvalidRR.NoExist'], true)) return null;
        if (!$response->successful() || !is_array($json) || array_key_exists('Code', $json)
            || $action === 'DescribeDomainRecordInfo' && ($json['RecordId'] ?? null) !== $data['RecordId']) {
            throw new RuntimeException('dns_read_rejected');
        }
        if ($action === 'DescribeDomainRecordInfo') {
            // Only the optional remark is normalized; exact ID and outer ownership checks stay mandatory.
            $json['Remark'] = $remark(array_key_exists('Remark', $json) ? $json['Remark'] : '');
        }
        if ($action === 'DescribeSubDomainRecords') {
            $rows = $json['DomainRecords']['Record'] ?? null;
            if (!is_array($rows) || !array_is_list($rows) || count($rows) > 100) throw new RuntimeException('dns_read_rejected');
            foreach ($rows as $index => $row) {
                if (!is_array($row)) throw new RuntimeException('dns_read_rejected');
                // Leave omitted list remarks absent so the caller still performs its exact-ID fallback.
                if (array_key_exists('Remark', $row)) $json['DomainRecords']['Record'][$index]['Remark'] =
                    $remark($row['Remark']);
            }
        }
        return $json;
    };
};
