<?php

namespace App\Services\ServerMachine\Dns;

class DnsException extends \RuntimeException
{
    public const ALIYUN_ACTIONS = ['DescribeDomains', 'DescribeDomainRecords', 'DescribeDomainRecordInfo',
        'DescribeSubDomainRecords', 'AddDomainRecord', 'UpdateDomainRecord', 'UpdateDomainRecordRemark', 'DeleteDomainRecord'];
    public const ALIYUN_CODES = ['InvalidRemark', 'InvalidRemark.TooLong', 'InvalidRemark.Length',
        'InvalidParameter', 'InvalidParameter.Remark', 'InvalidParameterValue', 'InvalidParameter.Length',
        'InvalidRecordId.NotFound', 'InvalidRR.NoExist', 'InvalidDomainName.NoExist', 'DomainRecordDuplicate',
        'DomainRecordNotBelongToUser', 'Forbidden', 'Forbidden.RAM', 'Forbidden.RAMPolicy',
        'InvalidAccessKeyId.NotFound', 'SignatureDoesNotMatch', 'SignatureNonceUsed', 'TimestampExpired',
        'Throttling', 'Throttling.User', 'InternalError', 'ServiceUnavailable'];
    private ?array $providerDiagnostic = null;

    public function withAliyunDiagnostic(string $action, ?int $status, mixed $code): static
    {
        $this->providerDiagnostic ??= ['schema' => 1, 'provider' => 'aliyun',
            'action' => in_array($action, self::ALIYUN_ACTIONS, true) ? $action : 'other',
            'http_status' => $status !== null && $status >= 100 && $status <= 599 ? $status : null,
            'code' => $code === null ? null : (in_array($code, self::ALIYUN_CODES, true) ? $code : 'other')];
        return $this;
    }

    public function providerDiagnostic(): ?array
    {
        return $this->providerDiagnostic;
    }
}
