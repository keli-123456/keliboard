<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ProtocolCapabilityService;
use App\Support\UserClientCompatibilityService;
use Tests\TestCase;

final class UserClientCompatibilityServiceTest extends TestCase
{
    private function service(): UserClientCompatibilityService
    {
        return new UserClientCompatibilityService(new ProtocolCapabilityService(require base_path('config/protocol_capabilities.php')));
    }

    private function plain(): array
    {
        return ['type' => 'vmess', 'protocol_settings' => ['network' => 'tcp', 'tls' => 0]];
    }

    private function ech(): array
    {
        return ['type' => 'hysteria', 'protocol_settings' => ['version' => 2, 'ech' => ['enabled' => true]]];
    }

    private function client(array $summary, string $key): array
    {
        return collect($summary['clients'])->firstWhere('key', $key);
    }

    public function test_one_ech_node_does_not_hide_clients_that_can_import_ordinary_nodes(): void
    {
        foreach ([[$this->plain(), $this->ech()], [$this->ech(), $this->plain()]] as $nodes) {
            $summary = $this->service()->summarize($nodes);
            foreach (['clashmeta', 'clashverge', 'hiddify', 'karing', 'shadowrocket'] as $key) {
                $client = $this->client($summary, $key);
                $this->assertSame('partial', $client['status'], $key);
                $this->assertSame(['total' => 2, 'supported' => 1, 'conditional' => 0, 'blocked' => 1], $client['node_counts']);
                $this->assertSame(['ech'], $client['limitations']);
                $this->assertContains($key, array_column($summary['partial_clients'], 'key'));
                $this->assertNotContains($key, array_column($summary['blocked_clients'], 'key'));
            }
        }
    }

    public function test_conditional_support_is_not_counted_as_confirmed_usable_nodes(): void
    {
        $summary = $this->service()->summarize([$this->plain(), $this->ech()]);
        $client = $this->client($summary, 'singbox');
        $this->assertSame('partial', $client['status']);
        $this->assertSame(['total' => 2, 'supported' => 1, 'conditional' => 1, 'blocked' => 0], $client['node_counts']);
        $this->assertNotContains('singbox', array_column($summary['allow_clients'], 'key'));
    }

    public function test_an_ech_only_pool_preserves_the_existing_strict_client_gate(): void
    {
        $summary = $this->service()->summarize([$this->ech()]);
        $this->assertSame(['singbox'], array_column($summary['partial_clients'], 'key'));
        $this->assertSame([], $summary['allow_clients']);
        $this->assertSame(['total' => 1, 'supported' => 0, 'conditional' => 1, 'blocked' => 0], $this->client($summary, 'singbox')['node_counts']);
        $this->assertSame('block', $this->client($summary, 'clashmeta')['status']);
    }

    public function test_completely_unknown_and_empty_pools_do_not_recommend_clients(): void
    {
        foreach ([[], [['type' => 'unknown-protocol']], [['type' => null]], [new \stdClass()]] as $nodes) {
            $summary = $this->service()->summarize($nodes);
            $this->assertSame([], $summary['allow_clients']);
            $this->assertSame([], $summary['partial_clients']);
            $this->assertCount(13, $summary['blocked_clients']);
        }
    }

    public function test_an_unknown_node_does_not_cancel_known_support_for_other_nodes(): void
    {
        $summary = $this->service()->summarize([['type' => 'unknown-protocol'], $this->plain(), $this->plain()]);
        $client = $this->client($summary, 'clashmeta');
        $this->assertSame('partial', $client['status']);
        $this->assertSame(['total' => 3, 'supported' => 2, 'conditional' => 0, 'blocked' => 1], $client['node_counts']);
        $this->assertSame(['unsupported_features'], $client['limitations']);
    }

    public function test_fully_supported_nodes_keep_the_allow_status_and_exact_counts(): void
    {
        $summary = $this->service()->summarize([$this->plain(), $this->plain()]);
        $client = $this->client($summary, 'clashmeta');
        $this->assertSame('allow', $client['status']);
        $this->assertSame(['total' => 2, 'supported' => 2, 'conditional' => 0, 'blocked' => 0], $client['node_counts']);
        $this->assertSame([], $client['limitations']);
        $this->assertSame(2, $summary['schema_version']);
        $this->assertSame(['VMess'], $summary['protocols']);
    }

    public function test_the_summary_does_not_relax_actual_export_checks(): void
    {
        $capabilities = new ProtocolCapabilityService(require base_path('config/protocol_capabilities.php'));
        $summary = (new UserClientCompatibilityService($capabilities))->summarize([$this->ech(), $this->plain()]);
        $this->assertSame('partial', $this->client($summary, 'clashmeta')['status']);
        $this->assertFalse($capabilities->supportsClient('meta', null, $this->ech())->supported);
        $this->assertFalse($capabilities->supportsClient('sing-box', null, $this->ech())->supported);
        $this->assertTrue($capabilities->supportsClient('meta', null, $this->plain())->supported);
    }
}
