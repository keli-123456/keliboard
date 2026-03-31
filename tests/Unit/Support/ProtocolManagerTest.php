<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Protocols\ClashMeta;
use App\Protocols\QuantumultX;
use App\Protocols\SingBox;
use App\Support\ProtocolManager;
use Illuminate\Container\Container;
use Tests\TestCase;

final class ProtocolManagerTest extends TestCase
{
    public function test_match_protocol_class_name_handles_normalized_user_agent_variants(): void
    {
        $manager = $this->makeManager([
            QuantumultX::class,
            ClashMeta::class,
            SingBox::class,
        ]);

        $this->assertSame(SingBox::class, $manager->matchProtocolClassName('singbox/1.12.0'));
        $this->assertSame(ClashMeta::class, $manager->matchProtocolClassName('ClashXMeta/1.3.5'));
        $this->assertSame(QuantumultX::class, $manager->matchProtocolClassName('quantumultx/1.0.31'));
        $this->assertSame(QuantumultX::class, $manager->matchProtocolClassName('Quantumult%20X/1.0.31'));
    }

    public function test_match_client_flag_returns_canonical_alias_for_variants(): void
    {
        $manager = $this->makeManager([
            QuantumultX::class,
            ClashMeta::class,
            SingBox::class,
        ]);

        $this->assertSame('sing-box', $manager->matchClientFlag('singbox'));
        $this->assertSame('clashx meta', $manager->matchClientFlag('clashxmeta'));
        $this->assertSame('quantumult-x', $manager->matchClientFlag('quantumultx'));
        $this->assertSame('quantumult%20x', $manager->matchClientFlag('Quantumult%20X'));
    }

    public function test_extract_client_version_handles_spaced_and_compact_user_agent_formats(): void
    {
        $manager = $this->makeManager([
            QuantumultX::class,
            ClashMeta::class,
            SingBox::class,
        ]);

        $this->assertSame('1.12.0', $manager->extractClientVersion('singbox 1.12.0', 'sing-box'));
        $this->assertSame('1.3.5', $manager->extractClientVersion('ClashX Meta/1.3.5', 'clashx meta'));
        $this->assertSame('1.7.0', $manager->extractClientVersion('Clash Verge/v1.7.0', 'verge'));
        $this->assertSame('1.0.31', $manager->extractClientVersion('quantumultx/1.0.31', 'quantumult-x'));
        $this->assertSame('1.0.31', $manager->extractClientVersion('Quantumult%20X/1.0.31', 'quantumult%20x'));
    }

    private function makeManager(array $classes): ProtocolManager
    {
        $manager = new ProtocolManager(new Container());

        $reflection = new \ReflectionProperty(ProtocolManager::class, 'protocolClasses');
        $reflection->setAccessible(true);
        $reflection->setValue($manager, $classes);

        return $manager;
    }
}
