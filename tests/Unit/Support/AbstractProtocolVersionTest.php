<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\AbstractProtocol;
use Tests\TestCase;

final class AbstractProtocolVersionTest extends TestCase
{
    public function test_base_version_is_enforced(): void
    {
        $protocol = new class ([], [[
            'type' => 'anytls',
            'protocol_settings' => [],
        ]], 'sing-box', '1.11.9') extends AbstractProtocol {
            public $flags = ['dummy'];
            protected $protocolRequirements = [
                'sing-box' => [
                    'anytls' => [
                        'base_version' => '1.12.0',
                    ],
                ],
            ];

            public function handle()
            {
                return $this->servers;
            }

            public function exposeServers(): array
            {
                return $this->servers;
            }
        };

        $this->assertCount(0, $protocol->exposeServers());
    }

    public function test_missing_version_is_filtered_conservatively_when_base_version_exists(): void
    {
        $protocol = new class ([], [[
            'type' => 'anytls',
            'protocol_settings' => [],
        ]], 'sing-box', null) extends AbstractProtocol {
            public $flags = ['dummy'];
            protected $protocolRequirements = [
                'sing-box' => [
                    'anytls' => [
                        'base_version' => '1.12.0',
                    ],
                ],
            ];

            public function handle()
            {
                return $this->servers;
            }

            public function exposeServers(): array
            {
                return $this->servers;
            }
        };

        $this->assertCount(0, $protocol->exposeServers());
    }

    public function test_base_version_allows_newer_client(): void
    {
        $protocol = new class ([], [[
            'type' => 'anytls',
            'protocol_settings' => [],
        ]], 'sing-box', '1.12.0') extends AbstractProtocol {
            public $flags = ['dummy'];
            protected $protocolRequirements = [
                'sing-box' => [
                    'anytls' => [
                        'base_version' => '1.12.0',
                    ],
                ],
            ];

            public function handle()
            {
                return $this->servers;
            }

            public function exposeServers(): array
            {
                return $this->servers;
            }
        };

        $this->assertCount(1, $protocol->exposeServers());
    }

    public function test_sing_box_four_segment_wrapper_version_bypasses_base_version_gate(): void
    {
        $protocol = new class ([], [[
            'type' => 'anytls',
            'protocol_settings' => [],
        ]], 'sing-box', '1.2.8.1103') extends AbstractProtocol {
            public $flags = ['dummy'];
            protected $protocolRequirements = [
                'sing-box' => [
                    'anytls' => [
                        'base_version' => '1.12.0',
                    ],
                ],
            ];

            public function handle()
            {
                return $this->servers;
            }

            public function exposeServers(): array
            {
                return $this->servers;
            }
        };

        $this->assertCount(1, $protocol->exposeServers());
    }

    public function test_mixed_case_requirement_keys_match_lowercase_client_name(): void
    {
        $protocol = new class ([], [[
            'type' => 'hysteria',
            'protocol_settings' => [
                'version' => 2,
            ],
        ]], 'clashx meta', '1.3.4') extends AbstractProtocol {
            public $flags = ['dummy'];
            protected $protocolRequirements = [
                'ClashX Meta.hysteria.protocol_settings.version' => [
                    2 => '1.3.5',
                ],
            ];

            public function handle()
            {
                return $this->servers;
            }

            public function exposeServers(): array
            {
                return $this->servers;
            }
        };

        $this->assertCount(0, $protocol->exposeServers());
    }
}
