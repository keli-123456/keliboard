<?php

namespace Plugin\NodeAutoRename\Commands;

use App\Models\Plugin as PluginModel;
use Illuminate\Console\Command;
use Plugin\NodeAutoRename\Services\NodeAutoRenameService;

class SyncNodeNames extends Command
{
    protected $signature = 'node:auto-rename
        {--server-id= : 仅同步指定节点 ID}
        {--dry-run : 仅预览，不写入数据库}
        {--force : 忽略 overwrite_existing 配置，强制改名}';

    protected $description = '根据协议、当前 IP 国家和节点 ID 自动同步节点名称';

    public function handle(): int
    {
        $serverId = $this->option('server-id');
        if ($serverId !== null && (!is_numeric($serverId) || (int) $serverId <= 0)) {
            $this->error('server-id 必须是正整数');
            return self::INVALID;
        }

        $service = new NodeAutoRenameService($this->loadPluginConfig());
        $result = $service->sync(
            serverId: $serverId !== null ? (int) $serverId : null,
            dryRun: (bool) $this->option('dry-run'),
            force: (bool) $this->option('force')
        );

        $this->info(sprintf(
            'scanned=%d renamed=%d skipped=%d failed=%d',
            (int) ($result['scanned'] ?? 0),
            (int) ($result['renamed'] ?? 0),
            (int) ($result['skipped'] ?? 0),
            (int) ($result['failed'] ?? 0)
        ));

        foreach ((array) ($result['changed'] ?? []) as $row) {
            $this->line(sprintf(
                '#%d %s => %s (%s %s)',
                (int) ($row['id'] ?? 0),
                (string) ($row['old_name'] ?? ''),
                (string) ($row['new_name'] ?? ''),
                (string) ($row['country'] ?? ''),
                (string) ($row['ip'] ?? '')
            ));
        }

        foreach ((array) ($result['errors'] ?? []) as $error) {
            $this->warn(sprintf(
                '#%d %s',
                (int) ($error['id'] ?? 0),
                (string) ($error['message'] ?? 'unknown error')
            ));
        }

        return ((int) ($result['failed'] ?? 0)) > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function loadPluginConfig(): array
    {
        $plugin = PluginModel::query()->where('code', 'node_auto_rename')->first();
        if (!$plugin || empty($plugin->config)) {
            return [];
        }

        $config = json_decode((string) $plugin->config, true);
        return is_array($config) ? $config : [];
    }
}
