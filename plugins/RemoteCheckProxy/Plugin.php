<?php

namespace Plugin\RemoteCheckProxy;

use App\Services\Plugin\AbstractPlugin;

class Plugin extends AbstractPlugin
{
    public function boot(): void
    {
        // Routes are auto-loaded from routes/api.php by PluginManager when enabled.
    }
}
