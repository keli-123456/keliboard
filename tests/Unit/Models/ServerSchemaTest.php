<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Server;
use Tests\TestCase;

final class ServerSchemaTest extends TestCase
{
    public function test_vless_protocol_enums_stay_aligned_with_runtime_safe_networks(): void
    {
        $networks = Server::getProtocolEnums(Server::TYPE_VLESS)['network'] ?? [];

        $this->assertContains('splithttp', $networks);
        $this->assertNotContains('kcp', $networks);
    }
}
