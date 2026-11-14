<?php
declare(strict_types=1);

// No autoloader or application boot: model only the old signing helper and a fake HTTP response.
namespace App\Services\ServerMachine\Dns {
    class DnsProviderClient
    {
        public static function signAliyun(array $parameters, string $secret): array { return $parameters; }
    }
}

namespace Illuminate\Support\Facades {
    class Http
    {
        public static int $requests = 0;
        public static function asForm(): self { return new self(); }
        public function acceptJson(): self { return $this; }
        public function withoutRedirecting(): self { return $this; }
        public function connectTimeout(int $seconds): self { return $this; }
        public function timeout(int $seconds): self { return $this; }
        public function post(string $url, array $parameters): self
        {
            if ($url !== 'https://alidns.aliyuncs.com/' || $parameters['Action'] !== 'DescribeDomainRecordInfo'
                || $parameters['RecordId'] !== '123') throw new \RuntimeException('unexpected_fixture_request');
            self::$requests++;
            return $this;
        }
        public function body(): string { return json_encode($this->json(), JSON_THROW_ON_ERROR); }
        public function status(): int { return 200; }
        public function successful(): bool { return true; }
        public function json(): array
        {
            return ['RecordId' => '123', 'Remark' => 'keli-set-b64-' . strtr(base64_encode(hex2bin(str_repeat('a', 48))), '+/', '-_')];
        }
    }
}

namespace {
    $factory = require dirname(__DIR__, 3) . '/tools/machine-acceptance/aliyun_cleanup.php';
    $reader = $factory(['access_key_id' => 'synthetic-only', 'access_key_secret' => 'synthetic-only'], fn () => 30);
    $actual = $reader('DescribeDomainRecordInfo', ['RecordId' => '123']);
    echo json_encode(['ok' => true, 'remark' => $actual['Remark'],
        'new_application_class_loaded' => class_exists('App\\Services\\ServerMachine\\Dns\\MachineDnsRecordSetProvider', false),
        'requests' => Illuminate\Support\Facades\Http::$requests], JSON_THROW_ON_ERROR);
}
