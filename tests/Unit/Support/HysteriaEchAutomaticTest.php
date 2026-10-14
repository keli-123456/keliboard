<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Services\Node\HysteriaEchProvisioningService as Provisioning;
use App\Services\Node\NodeConfigService;
use App\Support\Hysteria2Ech;
use App\Models\Server;
use InvalidArgumentException;
use Tests\TestCase;

final class HysteriaEchAutomaticTest extends TestCase
{
    private function settings(): array
    {
        return Provisioning::normalize(['version' => 2, 'tls' => ['server_name' => 'public.example.com', 'allow_insecure' => false],
            'ech' => ['enabled' => true, 'mode' => 'automatic'], 'obfs' => ['open' => false], 'bandwidth' => []]);
    }

    private function report(array $settings, string $phase = 'prepared'): array
    {
        return ['request_id' => $settings['ech']['request_id'], 'phase' => $phase,
            'config' => Hysteria2EchTest::publicConfig(), 'key_file' => '/etc/kelinode/ech/owned/key.pem'];
    }

    public function test_two_phase_flow_and_replays_preserve_public_identity(): void
    {
        $pending = $this->settings();
        $this->assertTrue(Hysteria2Ech::waiting($pending));
        $prepared = Provisioning::applyReport($pending, $this->report($pending));
        $this->assertSame('prepared', $prepared['ech']['state']);
        $this->assertNull(Hysteria2Ech::publicConfig($prepared));
        $this->assertSame($prepared, Provisioning::applyReport($prepared, $this->report($pending)));
        $ready = Provisioning::applyReport($prepared, $this->report($pending, 'ready'));
        $this->assertSame(Hysteria2EchTest::publicConfig(), Hysteria2Ech::publicConfig($ready));
        $this->assertSame($ready, Provisioning::applyReport($ready, $this->report($pending)));
        $this->assertSame($ready, Provisioning::applyReport($ready, $this->report($pending, 'ready')));
        $this->assertStringNotContainsString('ECH KEYS', json_encode($ready));
    }

    public function test_admin_round_trip_does_not_spoof_ready_or_rotate_existing_keys(): void
    {
        $old = $this->settings();
        $spoof = $old;
        $spoof['ech'] = ['enabled' => true, 'mode' => 'automatic', 'state' => 'ready',
            'request_id' => str_repeat('f', 32), 'config' => 'private', 'key_file' => '/untrusted'];
        $this->assertSame($old, Provisioning::normalize($spoof, $old));
        $new = Provisioning::normalize($spoof);
        $this->assertSame('pending', $new['ech']['state']);
        $this->assertArrayNotHasKey('config', $new['ech']);
        $this->assertNotSame($old['ech']['request_id'], $new['ech']['request_id']);
        $changedName = $old; $changedName['tls']['server_name'] = 'new.example.com';
        $this->assertNotSame($old['ech']['request_id'], Provisioning::normalize($changedName, $old)['ech']['request_id']);
        $manual = ['ech' => ['enabled' => true, 'key_file' => '/private', 'config' => 'old']];
        $this->assertSame($manual, Provisioning::normalize($manual));
    }

    public function test_pending_metadata_is_only_advertised_to_capable_nodes(): void
    {
        $node = (new Server())->forceFill(['type' => 'hysteria', 'host' => 'public.example.com', 'port' => '443',
            'server_port' => 443, 'protocol_settings' => $this->settings()]);
        $service = new NodeConfigService();
        $old = $service->buildResponse($node, true);
        $this->assertArrayNotHasKey('automatic_ech', $old);
        $this->assertNull($old['network_settings']);
        $new = $service->buildResponse($node, true, true);
        $this->assertSame('pending', $new['automatic_ech']['state']);
        $this->assertNull($new['automatic_ech']['config']);
        $this->assertNull($new['network_settings']);
    }

    public function test_mismatched_or_unprepared_reports_are_rejected(): void
    {
        $pending = $this->settings();
        $prepared = Provisioning::applyReport($pending, $this->report($pending));
        $cases = [[$pending, $this->report($pending, 'ready')]];
        foreach (['request_id' => str_repeat('f', 32), 'config' => Hysteria2EchTest::publicConfig(9),
            'key_file' => '/other/key.pem'] as $field => $value) {
            $report = $this->report($pending); $report[$field] = $value;
            $cases[] = [$prepared, $report];
        }
        $disabled = $pending; unset($disabled['ech']); $cases[] = [$disabled, $this->report($pending)];
        foreach ($cases as [$settings, $report]) {
            try { Provisioning::applyReport($settings, $report); $this->fail('Invalid report was accepted'); }
            catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }
}
