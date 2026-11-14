<?php
declare(strict_types=1);

require dirname(__DIR__, 3) . '/vendor/autoload.php';
require dirname(__DIR__, 3) . '/tools/machine-acceptance/cli_diagnostics.php';

$error = (new App\Services\ServerMachine\Dns\DnsException('provider_rejected'))
    ->withAliyunDiagnostic('UpdateDomainRecordRemark', 400, 'InvalidRemark.TooLong');
echo json_encode(['response' => keli_acceptance_cli_failure('initial-dns', $error),
    'actions' => App\Services\ServerMachine\Dns\DnsException::ALIYUN_ACTIONS,
    'codes' => App\Services\ServerMachine\Dns\DnsException::ALIYUN_CODES], JSON_THROW_ON_ERROR);
