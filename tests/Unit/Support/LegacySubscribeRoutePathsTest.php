<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\LegacySubscribeRoutePaths;
use Tests\TestCase;

final class LegacySubscribeRoutePathsTest extends TestCase
{
    public function test_default_aliases_keep_legacy_subscription_paths_without_current_path(): void
    {
        $aliases = LegacySubscribeRoutePaths::aliases('s');

        $this->assertSame(['sub', 'subscribe'], $aliases);
        $this->assertTrue(LegacySubscribeRoutePaths::shouldRegisterSiteTokenAlias('s', $aliases));
    }

    public function test_aliases_are_normalized_deduplicated_and_skip_current_path(): void
    {
        $aliases = LegacySubscribeRoutePaths::aliases('/sub/', "sub\nlegacy-s, subscribe, ../bad, legacy/s");

        $this->assertSame(['subscribe', 'legacy-s'], $aliases);
        $this->assertTrue(LegacySubscribeRoutePaths::shouldRegisterSiteTokenAlias('sub', $aliases));
    }
}
