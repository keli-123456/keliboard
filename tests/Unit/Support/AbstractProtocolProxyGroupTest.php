<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\AbstractProtocol;
use Tests\TestCase;

final class AbstractProtocolProxyGroupTest extends TestCase
{
    public function test_proxy_group_without_regex_appends_all_proxies(): void
    {
        $protocol = $this->protocol();

        $this->assertSame(
            ['DIRECT', 'Proxy A', 'Proxy B'],
            $protocol->apply(['DIRECT'], ['Proxy A', 'Proxy B'])
        );
    }

    public function test_proxy_group_with_regex_keeps_literals_and_appends_matches_in_source_order(): void
    {
        $protocol = $this->protocol();

        $this->assertSame(
            ['DIRECT', 'REJECT', 'HK 01', 'JP 01', 'US 01'],
            $protocol->apply(['DIRECT', '/HK|JP/', 'REJECT', '/US/'], ['HK 01', 'US 01', 'JP 01'])
        );
    }

    private function protocol(): object
    {
        return new class extends AbstractProtocol {
            public function __construct()
            {
                parent::__construct([], []);
            }

            public function handle()
            {
                return null;
            }

            public function apply(array $sources, array $proxies): array
            {
                return $this->buildProxyGroupProxies($sources, $proxies);
            }
        };
    }
}
