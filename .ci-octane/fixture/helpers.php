<?php

declare(strict_types=1);

function mountedFiles(): array
{
    $files = ['app/Http/Routes/V2/AdminRoute.php', 'app/Http/Controllers/V2/Admin/Server/MachineCloudController.php',
        'app/Http/Controllers/V2/Admin/Server/MachineFailoverExampleController.php', 'config/failover_example.php', 'config/v2board.php',
        'public/assets/admin-xboard/index.html', 'public/assets/admin-xboard/build-manifest.json',
        'public/assets/admin-xboard/assets/index.js', 'public/assets/admin-xboard/assets/index.css'];
    $out = [];
    foreach ($files as $name) $out[$name] = is_file('/www/' . $name) ? hash_file('sha256', '/www/' . $name) : null;
    return $out;
}
