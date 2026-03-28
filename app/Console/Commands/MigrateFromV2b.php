<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrateFromV2b extends Command
{
    protected $signature = 'migrateFromV2b {version?}';
    protected $description = '供不同版本V2b迁移到本项目的脚本';

    public function handle()
    {
        $version = $this->argument('version');
        if($version === 'config'){
            $this->MigrateV2ConfigToV2Settings();
            return;
        }

        // Define your SQL commands based on versions
        $sqlCommands = [
            'dev231027' => [
                // SQL commands for version Dev 2023/10/27
                'ALTER TABLE v2_order ADD COLUMN surplus_order_ids TEXT NULL;',
                'ALTER TABLE v2_plan DROP COLUMN daily_unit_price, DROP COLUMN transfer_unit_price;',
                'ALTER TABLE v2_server_hysteria DROP COLUMN ignore_client_bandwidth, DROP COLUMN obfs_type;'
            ],            
            '1.7.4' => [
                'CREATE TABLE `v2_server_vless` ( 
                    `id` INT AUTO_INCREMENT PRIMARY KEY, 
                    `group_id` TEXT NOT NULL, 
                    `route_id` TEXT NULL, 
                    `name` VARCHAR(255) NOT NULL,
                    `parent_id` INT NULL, 
                    `host` VARCHAR(255) NOT NULL, 
                    `port` INT NOT NULL, 
                    `server_port` INT NOT NULL, 
                    `tls` BOOLEAN NOT NULL, 
                    `tls_settings` TEXT NULL, 
                    `flow` VARCHAR(64) NULL, 
                    `network` VARCHAR(11) NOT NULL, 
                    `network_settings` TEXT NULL, 
                    `tags` TEXT NULL, 
                    `rate` VARCHAR(11) NOT NULL, 
                    `show` BOOLEAN DEFAULT 0, 
                    `sort` INT NULL, 
                    `created_at` INT NOT NULL, 
                    `updated_at` INT NOT NULL
                );'
            ],
            '1.7.3' => [
                'ALTER TABLE `v2_stat_order` RENAME TO `v2_stat`;',
                "ALTER TABLE `v2_stat` CHANGE COLUMN order_amount paid_total INT COMMENT '订单合计';",
                "ALTER TABLE `v2_stat` CHANGE COLUMN order_count paid_count INT COMMENT '邀请佣金';",
                "ALTER TABLE `v2_stat` CHANGE COLUMN commission_amount commission_total INT COMMENT '佣金合计';",
                "ALTER TABLE `v2_stat`
                    ADD COLUMN order_count INT NULL,
                    ADD COLUMN order_total INT NULL,
                    ADD COLUMN register_count INT NULL,
                    ADD COLUMN invite_count INT NULL,
                    ADD COLUMN transfer_used_total VARCHAR(32) NULL;
                ",  
                "CREATE TABLE `v2_log` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `title` TEXT NOT NULL,
                    `level` VARCHAR(11) NULL,
                    `host` VARCHAR(255) NULL,
                    `uri` VARCHAR(255) NOT NULL,
                    `method` VARCHAR(11) NOT NULL,
                    `data` TEXT NULL,
                    `ip` VARCHAR(128) NULL,
                    `context` TEXT NULL,
                    `created_at` INT NOT NULL,
                    `updated_at` INT NOT NULL
                );",
                'CREATE TABLE `v2_server_hysteria` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY,
                    `group_id` VARCHAR(255) NOT NULL,
                    `route_id` VARCHAR(255) NULL,
                    `name` VARCHAR(255) NOT NULL,
                    `parent_id` INT NULL,
                    `host` VARCHAR(255) NOT NULL,
                    `port` VARCHAR(11) NOT NULL,
                    `server_port` INT NOT NULL,
                    `tags` VARCHAR(255) NULL,
                    `rate` VARCHAR(11) NOT NULL,
                    `show` BOOLEAN DEFAULT FALSE,
                    `sort` INT NULL,
                    `up_mbps` INT NOT NULL,
                    `down_mbps` INT NOT NULL,
                    `server_name` VARCHAR(64) NULL,
                    `insecure` BOOLEAN DEFAULT FALSE,
                    `created_at` INT NOT NULL,
                    `updated_at` INT NOT NULL
                );',
                "CREATE TABLE `v2_server_vless` (
                    `id` INT AUTO_INCREMENT PRIMARY KEY, 
                    `group_id` TEXT NOT NULL, 
                    `route_id` TEXT NULL, 
                    `name` VARCHAR(255) NOT NULL, 
                    `parent_id` INT NULL, 
                    `host` VARCHAR(255) NOT NULL, 
                    `port` INT NOT NULL, 
                    `server_port` INT NOT NULL, 
                    `tls` BOOLEAN NOT NULL, 
                    `tls_settings` TEXT NULL, 
                    `flow` VARCHAR(64) NULL, 
                    `network` VARCHAR(11) NOT NULL, 
                    `network_settings` TEXT NULL, 
                    `tags` TEXT NULL, 
                    `rate` VARCHAR(11) NOT NULL, 
                    `show` BOOLEAN DEFAULT FALSE, 
                    `sort` INT NULL, 
                    `created_at` INT NOT NULL, 
                    `updated_at` INT NOT NULL
                );",
            ],
            'wyx2685' => [],
            'v2boardpro' => [
                // V2boardpro 表结构已是最新，无需修改
                // 所有迁移由 xboard:update 中的 create_v2_server_table 自动处理
            ]
        ];

        if (!$version) {
            $version = $this->choice('请选择你迁移前的V2board版本:', array_keys($sqlCommands));
        }

        if ($version === 'v2boardpro') {
            return $this->migrateFromV2boardpro();
        }

        if ($version === 'wyx2685') {
            return $this->migrateFromWyx2685();
        }

        if (array_key_exists($version, $sqlCommands)) {
            
            try {
                foreach ($sqlCommands[$version] as $sqlCommand) {
                    // Execute SQL command
                    DB::statement($sqlCommand);
                }
                
                $this->info('1️⃣、数据库差异矫正成功');

                // 初始化数据库迁移
                $this->call('db:seed', ['--class' => 'OriginV2bMigrationsTableSeeder']);
                $this->info('2️⃣、数据库迁移记录初始化成功');

                $this->call('xboard:update');
                $this->info('3️⃣、更新成功');

                $this->info("🎉：成功从 $version 迁移到Xboard");
            } catch (\Exception $e) {
                // An error occurred, rollback the transaction
                $this->error('迁移失败'. $e->getMessage() );
            }


        } else {
            $this->error("你所输入的版本未找到");
        }
    }

    public function MigrateV2ConfigToV2Settings()
    {
        Artisan::call('config:clear');
        $configValue = config('v2board') ?? [];

        foreach ($configValue as $k => $v) {
            // 检查记录是否已存在
            $existingSetting = Setting::where('name', $k)->first();
            
            // 如果记录不存在，则插入
            if ($existingSetting) {
                $this->warn("配置 {$k} 在数据库已经存在， 忽略");
                continue;
            }
            Setting::create([
                'name' => $k,
                'value' => is_array($v)? json_encode($v) : $v,
            ]);
            $this->info("配置 {$k} 迁移成功");
        }
        Artisan::call('config:cache');

        $this->info('所有配置迁移完成');
    }

    /**
     * 从 V2boardpro 迁移节点数据
     */
    protected function migrateFromV2boardpro()
    {
        return $this->migrateWithServerBackupFlow('V2boardpro', 'V2boardpro → Xboardpro');
    }

    /**
     * 从 wyx2685/v2board 迁移节点数据
     */
    protected function migrateFromWyx2685()
    {
        return $this->migrateWithServerBackupFlow('wyx2685/v2board', 'wyx2685/v2board → Xboardpro');
    }

    /**
     * 通过节点备份恢复的方式迁移旧版数据库
     */
    protected function migrateWithServerBackupFlow(string $sourceName, string $title)
    {
        $this->info('');
        $this->info('========================================');
        $this->info("  {$title} 节点迁移工具");
        $this->info('========================================');
        $this->info('');

        try {
            $this->info('0️⃣  准备迁移环境...');
            
            // 检查是否已经迁移过
            if (DB::getSchemaBuilder()->hasTable('v2_server')) {
                $serverCount = DB::table('v2_server')->count();
                if ($serverCount > 0) {
                    $this->warn('');
                    $this->warn('  ⚠️  检测到 v2_server 表中已有 ' . $serverCount . ' 个节点数据');
                    $this->warn('  ⚠️  可能已经执行过迁移，继续执行可能导致数据重复！');
                    $this->warn('');
                    
                    if (!$this->confirm('  确定要继续迁移吗？这可能导致数据重复。', false)) {
                        $this->info('');
                        $this->info('  ℹ️  迁移已取消');
                        return 0;
                    }
                }
            }
            
            // 检查数据库连接
            try {
                DB::connection()->getPdo();
                $this->line('  ✅ 数据库连接正常');
            } catch (\Exception $e) {
                $this->error('  ❌ 数据库连接失败: ' . $e->getMessage());
                return 1;
            }
            
            // 删除并重建 migrations 表（确保结构正确）
            $this->warn('  ⚠️  重建 migrations 表...');
            DB::statement('DROP TABLE IF EXISTS migrations');
            DB::statement("CREATE TABLE `migrations` (
                `id` int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
                `migration` varchar(255) NOT NULL,
                `batch` int NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
            $this->line('  ✅ migrations 表已重建（含 AUTO_INCREMENT）');
            
            // 删除 failed_jobs 表（避免结构冲突）
            if (DB::getSchemaBuilder()->hasTable('failed_jobs')) {
                $this->warn('  ⚠️  删除旧的 failed_jobs 表...');
                DB::statement('DROP TABLE failed_jobs');
                $this->line('  ✅ failed_jobs 表已删除');
            }
            
            $this->info('');
            
            $this->info('1️⃣  备份节点数据...');
            // 在备份前强制清理临时表（确保干净的起始状态）
            $this->cleanupTempBackupTable();
            $this->backupServerData();
            $this->info('✅ 节点数据已备份到临时表');

            $this->info('');
            $this->info('2️⃣  初始化迁移记录...');
            $this->call('db:seed', ['--class' => 'OriginV2bMigrationsTableSeeder']);
            $this->markCreateServerTableMigration();
            $this->info('✅ 数据库迁移记录初始化完成');

            $this->info('');
            $this->info('2.2️⃣  创建统一节点表...');
            $this->createServerTableIfNotExists();
            $this->info('✅ v2_server 表已就绪');

            $this->info('');
            $this->info('2.5️⃣  智能标记已存在字段的迁移...');
                $this->markExistingColumnMigrations();
                $this->info('✅ 字段检测完成');
                
                $this->info('');
                $this->info('2.8️⃣  预先修复 v2_stat 表结构（防止Xboard创建错误索引）...');
                $this->preFixStatTable();
                $this->info('✅ v2_stat 表结构已预先修复');
                
            $this->info('');
            $this->info('3️⃣  执行 Xboard 迁移（对齐表结构）...');
            $this->line('  📝 跳过已存在字段的添加');
            $this->line('  📝 执行字段修改和优化');
            $this->call('migrate', ['--force' => true]);
            $this->info('✅ Xboard 表结构对齐完成');

            $this->info('');
            $this->info('4️⃣  从备份恢复节点数据到 v2_server...');
            $this->restoreServerData();
            $this->info('✅ 节点数据已恢复');

            $this->info('');
            $this->info('4.5️⃣  清理旧节点分表...');
            $this->dropOldServerTables();
            $this->info('✅ 旧节点表已删除');

            $this->info('');
            $this->info('5️⃣  修复数据库配置和缓存...');
            $this->fixDatabaseAndCache();
            $this->info('✅ 配置和缓存已修复');
            
            $this->info('');
            $this->info('5.5️⃣  清理旧的session数据...');
            $this->cleanOldSessions();
            $this->info('✅ Session已清理');
            
            $this->info('');
            $this->info('5.8️⃣  最终修复所有关键问题...');
            $this->finalFixAllIssues();
            $this->info('✅ 所有关键问题已修复');

            $this->info('');
            $this->info('6️⃣  验证迁移结果...');
            $totalMigrated = $this->verifyServerMigration();
            $this->verifySystemTables();

            $this->info('');
            $this->info('========================================');
            $this->info("🎉 数据库迁移成功！共迁移 {$totalMigrated} 个节点");
            $this->info('========================================');
            $this->info('');
            $this->info("✅ {$sourceName} 数据库已成功对齐到 Xboardpro");
            $this->info('');
            $this->info('⚠️  重要提示：');
            $this->warn('  所有旧的 session 已清理，请重新登录管理面板');
            $this->info('');
            $this->info('📋 下一步操作：');
            $this->info('1. 【必须】清除浏览器缓存和Cookie');
            $this->info('2. 【必须】刷新页面并重新登录');
            $this->info('3. 验证节点列表是否正常显示');
            $this->info('4. 验证订单列表是否可以查看');
            $this->info('5. 验证统计数据是否正常');
            $this->info('');
            $this->info('如果仍有问题，请查看:');
            $this->info('  - storage/logs/laravel.log');
            $this->info('  - 运行: php validate_migration.php');
            $this->info('');
            
            return 0;

        } catch (\Exception $e) {
            $this->error('');
            $this->error('❌ 迁移失败：' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }
    }

    /**
     * 迁移指定类型的节点数据
     */
    protected function migrateServerData($type)
    {
        $tableName = 'v2_server_' . $type;
        
        if (!DB::getSchemaBuilder()->hasTable($tableName)) {
            $this->line("  ⏭️  跳过 {$type}（表不存在）");
            return 0;
        }

        $servers = DB::table($tableName)->get();
        $count = 0;

        foreach ($servers as $server) {
            $data = [
                'type' => $type,
                'code' => (string)$server->id,  // ✅ code 只保存数字，节点后端用这个对接
                'parent_id' => $server->parent_id,
                'group_ids' => $this->normalizeJson($server->group_id),
                'route_ids' => $this->normalizeJson($server->route_id ?? null),
                'name' => $server->name,
                'rate' => $server->rate,
                'tags' => $this->normalizeJson($server->tags ?? null),
                'host' => $server->host,
                'port' => $server->port,
                'server_port' => $server->server_port,
                'show' => $server->show ?? 0,
                'sort' => $server->sort ?? null,
                'created_at' => $server->created_at,
                'updated_at' => $server->updated_at,
            ];

            // 根据不同类型设置协议特定配置
            $data['protocol_settings'] = $this->getProtocolSettings($type, $server);

            DB::table('v2_server')->insert($data);
            $count++;
        }

        $this->line("  ✅ {$type}: {$count} 个节点");
        return $count;
    }

    /**
     * 获取协议特定配置
     */
    protected function getProtocolSettings($type, $server)
    {
        $settings = [];

        switch ($type) {
            case 'trojan':
                $settings = [
                    'allow_insecure' => $server->allow_insecure ?? 0,
                    'server_name' => $server->server_name ?? null,
                    'network' => $server->network ?? null,
                    'network_settings' => $this->parseJson($server->network_settings ?? null),
                ];
                break;

            case 'vmess':
                $settings = [
                    'tls' => $server->tls ?? 0,
                    'network' => $server->network ?? null,
                    'rules' => $this->parseJson($server->rules ?? null),
                    'network_settings' => $this->parseJson($server->networkSettings ?? null),
                    'tls_settings' => $this->parseJson($server->tlsSettings ?? null),
                ];
                break;

            case 'vless':
                $settings = [
                    'tls' => $server->tls ?? 0,
                    'tls_settings' => $this->parseJson($server->tls_settings ?? null),
                    'flow' => $server->flow ?? null,
                    'network' => $server->network ?? null,
                    'network_settings' => $this->parseJson($server->network_settings ?? null),
                ];
                break;

            case 'shadowsocks':
                $settings = [
                    'cipher' => $server->cipher ?? null,
                    'obfs' => $server->obfs ?? null,
                    'obfs_settings' => $this->parseJson($server->obfs_settings ?? null),
                ];
                break;

            case 'hysteria':
                $settings = [
                    'version' => $server->version ?? 2,
                    'bandwidth' => [
                        'up' => $server->up_mbps ?? 100,
                        'down' => $server->down_mbps ?? 100,
                    ],
                    'obfs' => [
                        'open' => $server->is_obfs ?? 0,
                        'type' => 'salamander',
                        'password' => $server->obfs_password ?? null,
                    ],
                    'tls' => [
                        'server_name' => $server->server_name ?? null,
                        'allow_insecure' => $server->insecure ?? 0,
                    ],
                ];
                break;

            case 'tuic':
                $settings = [
                    'version' => $server->version ?? 5,
                    'server_name' => $server->server_name ?? null,
                    'congestion_control' => $server->congestion_control ?? null,
                    'insecure' => $server->insecure ?? 0,
                    'disable_sni' => $server->disable_sni ?? 0,
                ];
                break;

            case 'anytls':
                $settings = [
                    'server_name' => $server->server_name ?? null,
                    'insecure' => $server->insecure ?? 0,
                    'padding_scheme' => $this->parseJson($server->padding_scheme ?? null),
                ];
                break;
        }

        return json_encode($settings);
    }

    /**
     * 更新节点父节点引用
     */
    protected function updateServerParentIds()
    {
        $servers = DB::table('v2_server')
            ->whereNotNull('parent_id')
            ->get();

        $updated = 0;
        foreach ($servers as $server) {
            // parent_id 存储的是原始的 ID，直接用数字查找（code 只存数字）
            $parentId = DB::table('v2_server')
                ->where('type', $server->type)
                ->where('code', (string)$server->parent_id)
                ->value('id');

            if ($parentId) {
                DB::table('v2_server')
                    ->where('id', $server->id)
                    ->update(['parent_id' => $parentId]);
                $updated++;
            }
        }

        if ($updated > 0) {
            $this->line("  更新了 {$updated} 个父节点引用");
        }
    }

    /**
     * 验证节点迁移结果
     */
    protected function verifyServerMigration()
    {
        $stats = DB::table('v2_server')
            ->select('type', DB::raw('COUNT(*) as count'))
            ->groupBy('type')
            ->get();

        if ($stats->isEmpty()) {
            $this->warn('  ⚠️  未找到任何节点数据');
            return 0;
        }

        $this->table(
            ['节点类型', '数量'], 
            $stats->map(function ($item) {
                return [$item->type, $item->count];
            })->toArray()
        );

        // 返回总数
        return $stats->sum('count');
    }

    /**
     * 标准化 JSON 格式
     */
    protected function normalizeJson($value)
    {
        if (empty($value) || $value === null) {
            return '[]';
        }
        
        if (is_string($value) && json_decode($value) !== null) {
            return $value;
        }
        
        if (is_string($value)) {
            $array = array_filter(explode(',', $value));
            return json_encode($array);
        }
        
        return '[]';
    }

    /**
     * 解析 JSON 字段
     */
    protected function parseJson($value)
    {
        if (empty($value) || $value === null) {
            return null;
        }
        
        // 如果已经是数组或对象，直接返回（转为数组）
        if (is_array($value)) {
            return $value;
        }
        if (is_object($value)) {
            return json_decode(json_encode($value), true);
        }
        
        // 如果是 JSON 字符串，解码
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return $decoded !== null ? $decoded : null;
        }
        
        return null;
    }

    /**
     * 智能标记已存在字段的"添加字段"迁移
     * V2boardpro 可能已有这些字段，标记为已执行以避免 Duplicate column 错误
     * 但保留字段，让后续的"修改字段"迁移正常运行
     */
    protected function markExistingColumnMigrations()
    {
        $schemaBuilder = DB::getSchemaBuilder();
        
        // 定义"添加字段"的迁移及其对应的表和字段
        $addColumnMigrations = [
            // v2_plan 表
            '2025_01_10_152139_add_device_limit_column' => ['table' => 'v2_plan', 'columns' => ['device_limit']],
            '2024_03_24_171139_add_column_capacity_limit_to_v2_plan_table' => ['table' => 'v2_plan', 'columns' => ['capacity_limit']],
            '2025_01_04_optimize_plan_table' => ['table' => 'v2_plan', 'columns' => ['period', 'reset_traffic_method']],
            
            // v2_user 表
            '2025_01_10_152140_add_device_limit_to_users' => ['table' => 'v2_user', 'columns' => ['device_limit']],
            '2024_04_25_164827_add_column_banned_reason_to_v2_user_table' => ['table' => 'v2_user', 'columns' => ['banned_reason']],
            
            // v2_order 表
            '2024_11_09_111424_add_column_surplus_order_ids_to_v2_order_table' => ['table' => 'v2_order', 'columns' => ['surplus_order_ids']],
            '2025_01_01_130644_modify_commission_status_in_v2_order_table' => ['table' => 'v2_order', 'columns' => ['commission_status']],
            '2025_01_12_200936_modify_commission_status_in_v2_order_table' => ['table' => 'v2_order', 'columns' => ['commission_status']],
            
            // v2_coupon 表
            '2024_03_17_170331_add_column_limit_plan_ids_to_v2_coupon_table' => ['table' => 'v2_coupon', 'columns' => ['limit_plan_ids']],
            '2024_03_21_102946_add_column_limit_period_to_v2_coupon_table' => ['table' => 'v2_coupon', 'columns' => ['limit_period']],
            
            // v2_notice 表
            '2025_01_12_190315_add_sort_to_v2_notice_table' => ['table' => 'v2_notice', 'columns' => ['sort']],
        ];
        
        $marked = 0;
        $skipped = 0;
        
        foreach ($addColumnMigrations as $migration => $config) {
            $table = $config['table'];
            $columns = $config['columns'];
            
            // 检查表和所有字段是否存在
            if (!$schemaBuilder->hasTable($table)) {
                $skipped++;
                continue;
            }
            
            $allColumnsExist = true;
            foreach ($columns as $column) {
                if (!$schemaBuilder->hasColumn($table, $column)) {
                    $allColumnsExist = false;
                    break;
                }
            }
            
            if ($allColumnsExist) {
                // 字段已存在，标记迁移为已执行
                $exists = DB::table('migrations')
                    ->where('migration', $migration)
                    ->exists();
                
                if (!$exists) {
                    DB::table('migrations')->insert([
                        'migration' => $migration,
                        'batch' => 1
                    ]);
                    $marked++;
                    $columnsStr = implode(', ', $columns);
                    $this->line("  ✅ 已标记: {$table}.{$columnsStr} (字段已存在，跳过添加)");
                }
            } else {
                $skipped++;
            }
        }
        
        $this->line("  📊 已标记 {$marked} 个添加字段迁移");
        
        // 标记所有针对旧节点表的迁移（这些表已被删除，统一到 v2_server）
        $this->markOldServerTableMigrations();
        
        // 同时检查并标记那些依赖于不存在字段的索引迁移
        $this->markIndexMigrationsForMissingColumns();
    }
    
    /**
     * 标记所有针对旧节点表的迁移
     * 这些表已经被删除并合并到 v2_server，相关迁移需要标记为已执行
     */
    protected function markOldServerTableMigrations()
    {
        // 所有针对旧节点表的迁移（这些表已删除并合并到 v2_server）
        $oldServerTableMigrations = [
            '2023_09_04_190923_add_column_excludes_to_server_table',
            '2023_09_06_195956_add_column_ips_to_server_table',
            '2023_09_14_013244_add_column_alpn_to_server_hysteria_table',
            '2023_09_24_040317_add_column_network_and_network_settings_to_v2_server_trojan',
            '2023_09_29_044957_add_column_version_and_is_obfs_to_server_hysteria_table',
            '2023_11_29_181412_add_column_network_settings_to_server_table',
            '2023_12_20_104825_add_column_network_settings_to_server_table',
            '2024_01_15_000000_add_column_to_server_tables',
            '2024_02_10_000000_update_server_tables',
            '2024_03_01_000000_add_excludes_to_servers',
        ];
        
        $marked = 0;
        foreach ($oldServerTableMigrations as $migration) {
            $exists = DB::table('migrations')
                ->where('migration', $migration)
                ->exists();
            
            if (!$exists) {
                DB::table('migrations')->insert([
                    'migration' => $migration,
                    'batch' => 1
                ]);
                $marked++;
                $this->line("  ✅ 已标记: {$migration} (旧节点表已删除)");
            }
        }
        
        if ($marked > 0) {
            $this->line("  📊 已标记 {$marked} 个旧节点表迁移");
        }
    }
    
    /**
     * 标记那些依赖于不存在字段的索引/外键迁移
     * 避免因为字段不存在导致迁移失败
     */
    protected function markIndexMigrationsForMissingColumns()
    {
        $schemaBuilder = DB::getSchemaBuilder();
        
        // 定义需要检查的索引迁移及其依赖的字段
        $indexMigrations = [
            '2025_01_15_000002_add_stat_performance_indexes' => [
                ['table' => 'v2_user', 'column' => 'online_count'],
                ['table' => 'v2_stat_server', 'column' => 'rate'],
            ],
            // 可以继续添加其他类似的迁移
        ];
        
        $marked = 0;
        
        foreach ($indexMigrations as $migration => $dependencies) {
            $shouldSkip = false;
            
            foreach ($dependencies as $dep) {
                $table = $dep['table'];
                $column = $dep['column'];
                
                // 如果表不存在，或字段不存在
                if (!$schemaBuilder->hasTable($table) || !$schemaBuilder->hasColumn($table, $column)) {
                    $shouldSkip = true;
                    $this->line("  ⏭️  跳过: {$migration} (缺少 {$table}.{$column})");
                    break;
                }
            }
            
            if ($shouldSkip) {
                $exists = DB::table('migrations')
                    ->where('migration', $migration)
                    ->exists();
                
                if (!$exists) {
                    DB::table('migrations')->insert([
                        'migration' => $migration,
                        'batch' => 1
                    ]);
                    $marked++;
                }
            }
        }
        
        if ($marked > 0) {
            $this->line("  📊 已跳过 {$marked} 个依赖不存在字段的迁移");
        }
    }

    /**
     * 清理临时备份表（独立函数，确保在任何操作前执行）
     */
    protected function cleanupTempBackupTable()
    {
        $this->line("  🗑️  清理旧的临时备份表...");
        
        try {
            // 多次尝试删除，确保表被清除
            for ($i = 0; $i < 3; $i++) {
                DB::statement("DROP TABLE IF EXISTS `v2_server_backup_temp`");
                
                // 验证是否真的删除
                $tableExists = DB::select("SHOW TABLES LIKE 'v2_server_backup_temp'");
                if (empty($tableExists)) {
                    $this->line("  ✅ 临时表已清理");
                    return;
                }
                
                // 如果还存在，等待一下再试
                if ($i < 2) {
                    $this->warn("  ⚠️  第 " . ($i + 1) . " 次删除未生效，重试...");
                    usleep(100000); // 等待 0.1 秒
                }
            }
            
            // 如果3次都失败，强制删除（不使用 IF EXISTS）
            DB::statement("DROP TABLE `v2_server_backup_temp`");
            $this->line("  ✅ 强制删除临时表成功");
            
        } catch (\Exception $e) {
            $this->line("  ℹ️  临时表不存在或已清理");
        }
    }
    
    /**
     * 备份所有节点数据到临时表
     */
    protected function backupServerData()
    {
        // 创建全新的临时备份表
        DB::statement("CREATE TABLE `v2_server_backup_temp` (
            `id` int NOT NULL,
            `type` varchar(20) NOT NULL,
            `source` varchar(64) NOT NULL,
            `data` longtext NOT NULL,
            PRIMARY KEY (`source`, `id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $this->line("  ✅ 临时备份表已创建");
        
        // 备份其他重要数据
        $this->backupOtherData();
        
        // 备份各类节点
        $serverTypes = [
            'v2_server_trojan' => 'trojan',
            'v2_server_vmess' => 'vmess',
            'v2_server_vless' => 'vless',
            'v2_server_shadowsocks' => 'shadowsocks',
            'v2_server_hysteria' => 'hysteria',
            'v2_server_tuic' => 'tuic',
            'v2_server_anytls' => 'anytls',
            'v2_server_v2node' => null,
        ];
        
        $totalBackedUp = 0;
        $skippedDuplicates = 0;
        
        foreach ($serverTypes as $table => $type) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                $servers = DB::table($table)->get();
                $typeBackedUp = 0;
                $typeStats = [];
                
                foreach ($servers as $server) {
                    try {
                        $normalizedType = $type ?? $this->normalizeLegacyNodeType($server->protocol ?? null);
                        if (!$normalizedType) {
                            $this->warn("    ⚠️  跳过不支持的节点协议: {$table} ID {$server->id}");
                            $skippedDuplicates++;
                            continue;
                        }

                        // 检查是否已存在（避免重复插入）
                        $exists = DB::table('v2_server_backup_temp')
                            ->where('source', $table)
                            ->where('id', $server->id)
                            ->exists();
                        
                        if ($exists) {
                            $this->warn("    ⚠️  跳过重复节点: {$table} ID {$server->id}");
                            $skippedDuplicates++;
                            continue;
                        }
                        
                        // 插入备份数据
                        DB::table('v2_server_backup_temp')->insert([
                            'id' => $server->id,
                            'type' => $normalizedType,
                            'source' => $table,
                            'data' => json_encode($server)
                        ]);
                        $totalBackedUp++;
                        $typeBackedUp++;
                        $typeStats[$normalizedType] = ($typeStats[$normalizedType] ?? 0) + 1;
                    } catch (\Exception $e) {
                        $this->warn("    ⚠️  备份节点失败: {$table} ID {$server->id} - " . $e->getMessage());
                        $skippedDuplicates++;
                    }
                }
                
                if ($typeBackedUp > 0) {
                    $detail = collect($typeStats)
                        ->map(fn ($count, $serverType) => "{$serverType}: {$count}")
                        ->implode(', ');
                    $this->line("  ✅ 备份 {$table}: {$typeBackedUp} 个节点" . ($detail !== '' ? " ({$detail})" : ''));
                } else {
                    $this->line("  ⏭️  跳过 {$table}: 0 个节点");
                }
            }
        }
        
        if ($skippedDuplicates > 0) {
            $this->warn("  ⚠️  跳过重复/错误节点: {$skippedDuplicates} 个");
        }
        $this->line("  📊 总计备份: {$totalBackedUp} 个节点");
    }

    /**
     * 从备份恢复节点数据到 v2_server 表
     */
    protected function restoreServerData()
    {
        if (!DB::getSchemaBuilder()->hasTable('v2_server_backup_temp')) {
            $this->warn('  ⚠️  未找到备份表');
            return;
        }
        
        if (!DB::getSchemaBuilder()->hasTable('v2_server')) {
            $this->error('  ❌ v2_server 表不存在，无法恢复');
            return;
        }
        
        $backups = DB::table('v2_server_backup_temp')
            ->orderBy('source')
            ->orderBy('id')
            ->get();
        $totalRestored = 0;
        $usedCodes = [];
        $restoredIdMap = [];
        $pendingParentUpdates = [];
        
        foreach ($backups as $backup) {
            $server = json_decode($backup->data);
            $type = $backup->type;
            $code = $this->resolveLegacyServerCode($type, (string) $server->id, $backup->source, $usedCodes);
            
            // 准备基础数据（注意 Xboard 使用 group_ids 和 route_ids 复数形式）
            $serverData = [
                'type' => $type,
                'code' => $code,
                'group_ids' => isset($server->group_id) ? (is_string($server->group_id) ? $server->group_id : json_encode($server->group_id)) : '[]',
                'route_ids' => isset($server->route_id) ? (is_string($server->route_id) ? $server->route_id : json_encode($server->route_id)) : '[]',
                'name' => $server->name,
                'parent_id' => $server->parent_id ?? null,
                'host' => $server->host,
                'port' => (string)($server->port ?? $server->server_port),
                'server_port' => $server->server_port,
                'tags' => isset($server->tags) ? (is_string($server->tags) ? $server->tags : json_encode($server->tags)) : '[]',
                'rate' => $server->rate ?? 1,
                'show' => $server->show ?? 0,
                'sort' => $server->sort ?? null,
                'created_at' => isset($server->created_at) ? date('Y-m-d H:i:s', $server->created_at) : now(),
                'updated_at' => isset($server->updated_at) ? date('Y-m-d H:i:s', $server->updated_at) : now(),
            ];
            
            // 根据类型设置 protocol_settings
            $serverData['protocol_settings'] = $this->buildProtocolSettings($type, $server, $backup->source);
            
            // 插入到 v2_server
            $insertedId = DB::table('v2_server')->insertGetId($serverData);
            $restoredIdMap[$this->buildRestoreMapKey($backup->source, $type, (string) $server->id)] = $insertedId;
            if (!empty($server->parent_id)) {
                $pendingParentUpdates[] = [
                    'server_id' => $insertedId,
                    'source' => $backup->source,
                    'type' => $type,
                    'parent_id' => (string) $server->parent_id,
                ];
            }
            $totalRestored++;
        }

        foreach ($pendingParentUpdates as $pendingParent) {
            $parentKey = $this->buildRestoreMapKey(
                $pendingParent['source'],
                $pendingParent['type'],
                $pendingParent['parent_id']
            );
            $parentId = $restoredIdMap[$parentKey]
                ?? DB::table('v2_server')
                    ->where('type', $pendingParent['type'])
                    ->where('code', $pendingParent['parent_id'])
                    ->value('id');

            if ($parentId) {
                DB::table('v2_server')
                    ->where('id', $pendingParent['server_id'])
                    ->update(['parent_id' => $parentId]);
            }
        }
        
        $this->line("  📊 总计恢复: {$totalRestored} 个节点");
        
        // 清理临时表
        DB::statement('DROP TABLE IF EXISTS v2_server_backup_temp');
        $this->line("  🗑️  已清理临时备份表");
    }
    
    /**
     * 备份其他重要数据
     */
    protected function backupOtherData()
    {
        // 清理旧的 v2_giftcard 表（不迁移，礼品卡不是重要数据）
        if (DB::getSchemaBuilder()->hasTable('v2_giftcard')) {
            $giftcardCount = DB::table('v2_giftcard')->count();
            
            if ($giftcardCount > 0) {
                $this->warn("  ⚠️  发现旧的 v2_giftcard 表 ({$giftcardCount} 条记录)");
                $this->line("  🗑️  礼品卡不是重要数据，将直接删除");
                DB::statement("DROP TABLE v2_giftcard");
                $this->line("  ✅ v2_giftcard 表已删除");
            } else {
                $this->line("  🗑️  删除空的 v2_giftcard 表");
                DB::statement("DROP TABLE v2_giftcard");
            }
        }
        
        // 清理可能存在的备份表
        if (DB::getSchemaBuilder()->hasTable('v2_giftcard_backup')) {
            $backupCount = DB::table('v2_giftcard_backup')->count();
            $this->warn("  ⚠️  发现旧的备份表 v2_giftcard_backup ({$backupCount} 条记录)");
            $this->line("  🗑️  删除备份表");
            DB::statement("DROP TABLE v2_giftcard_backup");
            $this->line("  ✅ v2_giftcard_backup 表已删除");
        }
    }
    
    /**
     * 构建 protocol_settings JSON
     */
    protected function buildProtocolSettings($type, $server, ?string $source = null)
    {
        if ($source === 'v2_server_v2node') {
            return json_encode($this->buildProtocolSettingsFromV2node($type, $server));
        }

        $settings = [];
        
        switch ($type) {
            case 'hysteria':
                $obfsPassword = $server->obfs_password ?? null;
                $obfsType = $server->obfs ?? 'salamander';
                $settings = [
                    'version' => $server->version ?? 1,
                    'bandwidth' => [
                        'up' => $server->up_mbps ?? 100,
                        'down' => $server->down_mbps ?? 100,
                    ],
                    'obfs' => [
                        'open' => !empty($obfsPassword) || !empty($server->obfs),
                        'type' => $obfsType,
                        'password' => $obfsPassword,
                    ],
                    'tls' => [
                        'server_name' => $server->server_name ?? '',
                        'allow_insecure' => (bool) ($server->insecure ?? 0)
                    ]
                ];
                if (isset($server->alpn)) {
                    $settings['alpn'] = $this->normalizeStringArray($server->alpn);
                }
                break;
                
            case 'trojan':
                $settings = [
                    'server_name' => $server->server_name ?? '',
                    'allow_insecure' => (bool) ($server->allow_insecure ?? 0),
                    'network' => $server->network ?? 'tcp',
                ];
                $networkSettings = $this->parseJson($server->network_settings ?? $server->networkSettings ?? null);
                if ($networkSettings !== null) {
                    $settings['network_settings'] = $networkSettings;
                }
                break;
                
            case 'vmess':
                $settings = [
                    'tls' => $server->tls ?? 0,
                    'network' => $server->network ?? 'tcp',
                ];
                $tlsSettings = $this->parseJson($server->tlsSettings ?? $server->tls_settings ?? null);
                if ($tlsSettings !== null) {
                    $settings['tls_settings'] = $tlsSettings;
                }
                $networkSettings = $this->parseJson($server->networkSettings ?? $server->network_settings ?? null);
                if ($networkSettings !== null) {
                    $settings['network_settings'] = $networkSettings;
                }
                if (isset($server->rules)) {
                    $settings['rules'] = $this->parseJson($server->rules);
                }
                break;
                
            case 'vless':
                $settings = [
                    'tls' => $server->tls ?? 0,
                    'network' => $server->network ?? 'tcp',
                ];
                
                // 处理 TLS/Reality 配置
                $tlsSettings = $this->parseJson($server->tls_settings ?? $server->tlsSettings ?? null);
                if ($tlsSettings !== null) {
                    $settings['tls_settings'] = $tlsSettings;
                    if (($server->tls ?? 0) == 2) {
                        $settings['reality_settings'] = $tlsSettings;
                    }
                }
                
                $networkSettings = $this->parseJson($server->network_settings ?? $server->networkSettings ?? null);
                if ($networkSettings !== null) {
                    $settings['network_settings'] = $networkSettings;
                }
                if (isset($server->flow)) {
                    $settings['flow'] = $server->flow;
                }
                break;
                
            case 'shadowsocks':
                $settings = [
                    'cipher' => $server->cipher ?? 'aes-256-gcm',
                ];
                if (isset($server->obfs)) {
                    $settings['obfs'] = $server->obfs;
                }
                if (isset($server->obfs_settings)) {
                    $settings['obfs_settings'] = $this->parseJson($server->obfs_settings);
                }
                // 添加 server_key 字段以确保兼容性
                if (isset($server->server_key)) {
                    $settings['server_key'] = $server->server_key;
                }
                break;
                
            case 'tuic':
                $settings = [
                    'version' => $server->version ?? 5,
                    'congestion_control' => $server->congestion_control ?? 'cubic',
                    'udp_relay_mode' => $server->udp_relay_mode ?? 'native',
                    'zero_rtt_handshake' => (bool) ($server->zero_rtt_handshake ?? 0),
                    'server_name' => $server->server_name ?? '',
                    'allow_insecure' => (bool) ($server->insecure ?? 0),
                    'tls' => [
                        'server_name' => $server->server_name ?? '',
                        'allow_insecure' => (bool) ($server->insecure ?? 0),
                    ],
                    'alpn' => ['h3'],
                ];
                $tlsSettings = $this->parseJson($server->tls_settings ?? null);
                if ($tlsSettings !== null) {
                    $settings['tls_settings'] = $tlsSettings;
                }
                if (isset($server->disable_sni)) {
                    $settings['disable_sni'] = (bool) $server->disable_sni;
                }
                break;
                
            case 'anytls':
                $settings = [
                    'tls_mode' => 1,
                    'network' => $server->network ?? 'tcp',
                    'server_name' => $server->server_name ?? '',
                    'allow_insecure' => (bool) ($server->insecure ?? 0),
                    'tls' => [
                        'server_name' => $server->server_name ?? '',
                        'allow_insecure' => (bool) ($server->insecure ?? 0),
                    ],
                ];
                if (isset($server->padding_scheme)) {
                    $settings['padding_scheme'] = $this->parseJson($server->padding_scheme);
                }
                break;
        }
        
        return json_encode($settings);
    }

    /**
     * 将 wyx2685 的 v2_server_v2node 记录映射到统一 protocol_settings
     */
    protected function buildProtocolSettingsFromV2node(string $type, object $server): array
    {
        $tlsSettings = $this->parseJson($server->tls_settings ?? null);
        $networkSettings = $this->parseJson($server->network_settings ?? null);
        $encryptionSettings = $this->parseJson($server->encryption_settings ?? null);
        $paddingScheme = $this->normalizePaddingScheme($server->padding_scheme ?? null);
        $serverName = data_get($tlsSettings, 'server_name', '');
        $allowInsecure = (bool) data_get($tlsSettings, 'allow_insecure', false);

        return match ($type) {
            'trojan' => array_filter([
                'server_name' => $serverName,
                'allow_insecure' => $allowInsecure,
                'network' => $server->network ?? 'tcp',
                'network_settings' => $networkSettings,
                'tls_settings' => $tlsSettings,
            ], fn ($value) => $value !== null),
            'vmess' => array_filter([
                'tls' => (int) ($server->tls ?? 0),
                'network' => $server->network ?? 'tcp',
                'network_settings' => $networkSettings,
                'tls_settings' => $tlsSettings,
            ], fn ($value) => $value !== null),
            'vless' => array_filter([
                'tls' => (int) ($server->tls ?? 0),
                'tls_settings' => $tlsSettings,
                'reality_settings' => ((int) ($server->tls ?? 0) === 2) ? $tlsSettings : null,
                'flow' => $server->flow ?? null,
                'network' => $server->network ?? 'tcp',
                'network_settings' => $networkSettings,
                'encryption' => $server->encryption ?? null,
                'encryption_settings' => $encryptionSettings,
            ], fn ($value) => $value !== null),
            'shadowsocks' => array_filter([
                'cipher' => $server->cipher ?? 'aes-128-gcm',
                'network' => $server->network ?? 'tcp',
                'network_settings' => $networkSettings,
            ], fn ($value) => $value !== null),
            'hysteria' => [
                'version' => 2,
                'bandwidth' => [
                    'up' => (int) ($server->up_mbps ?? 100),
                    'down' => (int) ($server->down_mbps ?? 100),
                ],
                'obfs' => [
                    'open' => !empty($server->obfs) || !empty($server->obfs_password),
                    'type' => $server->obfs ?? 'salamander',
                    'password' => $server->obfs_password ?? null,
                ],
                'tls' => [
                    'server_name' => $serverName,
                    'allow_insecure' => $allowInsecure,
                ],
                'tls_settings' => $tlsSettings,
            ],
            'tuic' => array_filter([
                'version' => 5,
                'congestion_control' => $server->congestion_control ?? 'cubic',
                'udp_relay_mode' => $server->udp_relay_mode ?? 'native',
                'zero_rtt_handshake' => (bool) ($server->zero_rtt_handshake ?? 0),
                'disable_sni' => (bool) ($server->disable_sni ?? 0),
                'alpn' => ['h3'],
                'server_name' => $serverName,
                'allow_insecure' => $allowInsecure,
                'tls' => [
                    'server_name' => $serverName,
                    'allow_insecure' => $allowInsecure,
                ],
                'tls_settings' => $tlsSettings,
            ], fn ($value) => $value !== null),
            'anytls' => array_filter([
                'tls_mode' => ((int) ($server->tls ?? 1) === 2) ? 2 : 1,
                'network' => $server->network ?? 'tcp',
                'network_settings' => $networkSettings,
                'padding_scheme' => $paddingScheme,
                'server_name' => $serverName,
                'allow_insecure' => $allowInsecure,
                'tls' => ((int) ($server->tls ?? 1) === 2) ? null : [
                    'server_name' => $serverName,
                    'allow_insecure' => $allowInsecure,
                ],
                'tls_settings' => $tlsSettings,
                'reality_settings' => ((int) ($server->tls ?? 1) === 2) ? $tlsSettings : null,
            ], fn ($value) => $value !== null),
            default => [],
        };
    }

    protected function normalizeLegacyNodeType($protocol): ?string
    {
        if (!is_string($protocol) || $protocol === '') {
            return null;
        }

        return match ($protocol) {
            'hysteria2' => 'hysteria',
            'trojan', 'vmess', 'vless', 'shadowsocks', 'tuic', 'anytls' => $protocol,
            default => null,
        };
    }

    protected function resolveLegacyServerCode(string $type, string $originalCode, string $source, array &$usedCodes): string
    {
        $originalCode = trim($originalCode);
        if ($originalCode === '') {
            $originalCode = '0';
        }

        if (!isset($usedCodes[$type][$originalCode])) {
            $usedCodes[$type][$originalCode] = true;
            return $originalCode;
        }

        $prefix = str_replace('v2_server_', '', $source);
        $candidate = "{$prefix}-{$originalCode}";
        $suffix = 2;
        while (isset($usedCodes[$type][$candidate])) {
            $candidate = "{$prefix}-{$originalCode}-{$suffix}";
            $suffix++;
        }

        $usedCodes[$type][$candidate] = true;
        $this->warn("  ⚠️  检测到重复节点编码: {$type}#{$originalCode}，已改为 {$candidate}");

        return $candidate;
    }

    protected function buildRestoreMapKey(string $source, string $type, string $originalId): string
    {
        return "{$source}:{$type}:{$originalId}";
    }

    protected function normalizeStringArray($value, array $default = []): array
    {
        $parsed = $this->parseJson($value);
        if (is_array($parsed)) {
            return array_values(array_filter(array_map(fn ($item) => trim((string) $item), $parsed), fn ($item) => $item !== ''));
        }

        if (is_string($value) && trim($value) !== '') {
            return [trim($value)];
        }

        return $default;
    }

    protected function normalizePaddingScheme($value): ?array
    {
        $parsed = $this->parseJson($value);
        if (is_array($parsed)) {
            return $parsed;
        }

        if (is_string($value) && trim($value) !== '') {
            return [trim($value)];
        }

        return null;
    }

    protected function markCreateServerTableMigration(): void
    {
        $migration = '2025_01_05_131425_create_v2_server_table';
        $exists = DB::table('migrations')
            ->where('migration', $migration)
            ->exists();

        if (!$exists) {
            DB::table('migrations')->insert([
                'migration' => $migration,
                'batch' => 1,
            ]);
            $this->line("  ✅ 已标记: {$migration} (统一节点表改由迁移脚本手动创建)");
        }
    }

    /**
     * 删除旧的节点分表（对齐 Xboard 表结构）
     */
    protected function dropOldServerTables()
    {
        $oldTables = [
            'v2_server_trojan',
            'v2_server_vmess',
            'v2_server_vless',
            'v2_server_shadowsocks',
            'v2_server_hysteria',
            'v2_server_tuic',
            'v2_server_anytls',
            'v2_server_v2node',
        ];

        foreach ($oldTables as $table) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                DB::statement("DROP TABLE {$table}");
                $this->line("  ✅ {$table} 表已删除");
            }
        }
    }


    /**
     * 创建 v2_server 表（如果不存在）
     */
    protected function createServerTableIfNotExists()
    {
        if (DB::getSchemaBuilder()->hasTable('v2_server')) {
            $this->warn('  ⚠️  检测到旧的 v2_server 表，正在删除并重建...');
            DB::statement('DROP TABLE v2_server');
            $this->line('  ✅ 旧表已删除');
        }

        Schema::create('v2_server', function (Blueprint $table) {
            $table->id('id');
            $table->string('type')->comment('Server Type');
            $table->string('code')->nullable()->comment('Server Spectific Key');
            $table->unsignedInteger('parent_id')->nullable()->comment('Parent Server ID');
            $table->json('group_ids')->nullable()->comment('Group ID');
            $table->json('route_ids')->nullable()->comment('Route ID');
            $table->string('name')->comment('Server Name');
            $table->decimal('rate', 8, 2)->comment('Traffic Rate');
            $table->json('tags')->nullable()->comment('Server Tags');
            $table->string('host')->comment('Server Host');
            $table->string('port')->comment('Client Port');
            $table->integer('server_port')->comment('Server Port');
            $table->json('protocol_settings')->nullable();
            $table->boolean('show')->default(false)->comment('Show in List');
            $table->integer('sort')->nullable()->unsigned()->index();
            $table->timestamps();
            $table->unique(['type', 'code']);
        });

        $this->info('  ℹ️  已手动创建 v2_server 表，并跳过自动节点表合并迁移');
    }
    
    /**
     * 预先修复 v2_stat 表（在 Xboard 迁移之前）
     */
    protected function preFixStatTable()
    {
        // 如果 v2_stat 表存在，删除它，让我们创建正确的结构
        if (DB::getSchemaBuilder()->hasTable('v2_stat')) {
            $this->warn('  ⚠️  删除旧的 v2_stat 表（将重新创建正确结构）...');
            
            // 备份数据
            $statData = DB::table('v2_stat')->get();
            $this->line('  💾 已备份 ' . count($statData) . ' 条统计记录');
            
            // 删除表
            DB::statement('DROP TABLE v2_stat');
            $this->line('  ✅ 旧表已删除');
        }
        
        // 创建正确结构的 v2_stat 表
        $this->line('  🔧 创建正确结构的 v2_stat 表...');
        DB::statement("CREATE TABLE IF NOT EXISTS `v2_stat` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `record_at` int(11) NOT NULL,
            `record_type` char(1) NOT NULL COMMENT 'd day m month',
            `order_count` int(11) DEFAULT 0 COMMENT '订单数量',
            `order_total` int(11) DEFAULT 0 COMMENT '订单合计',
            `commission_count` int(11) DEFAULT 0,
            `commission_total` int(11) DEFAULT 0 COMMENT '佣金合计',
            `paid_count` int(11) DEFAULT 0,
            `paid_total` int(11) DEFAULT 0,
            `register_count` int(11) DEFAULT 0,
            `invite_count` int(11) DEFAULT 0,
            `transfer_used_total` varchar(32) DEFAULT '0',
            `created_at` int(11) NOT NULL,
            `updated_at` int(11) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `record_at_record_type` (`record_at`, `record_type`),
            KEY `idx_record_at` (`record_at`),
            KEY `idx_record_type` (`record_type`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
        $this->line('  ✅ v2_stat 表已创建（带复合唯一键）');
        
        // 恢复备份的数据（如果有）
        if (isset($statData) && count($statData) > 0) {
            $this->line('  🔄 恢复统计数据...');
            
            foreach ($statData as $row) {
                try {
                    DB::table('v2_stat')->insert([
                        'record_at' => $row->record_at,
                        'record_type' => $row->record_type,
                        'order_count' => $row->order_count ?? 0,
                        'order_total' => $row->order_total ?? 0,
                        'commission_count' => $row->commission_count ?? 0,
                        'commission_total' => $row->commission_total ?? 0,
                        'paid_count' => $row->paid_count ?? 0,
                        'paid_total' => $row->paid_total ?? 0,
                        'register_count' => $row->register_count ?? 0,
                        'invite_count' => $row->invite_count ?? 0,
                        'transfer_used_total' => $row->transfer_used_total ?? '0',
                        'created_at' => $row->created_at ?? time(),
                        'updated_at' => $row->updated_at ?? time()
                    ]);
                } catch (\Exception $e) {
                    // 如果插入失败（重复键），跳过
                    if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                        throw $e;
                    }
                }
            }
            
            $this->line('  ✅ 统计数据已恢复');
        }
        
        // 注意：不标记 create_v2_tables 迁移，因为它还会创建其他必要的表
        // v2_stat 表已经由我们预先创建，Xboard 迁移会检测到表存在并跳过创建
        $this->line('  ℹ️  v2_stat 表已预先创建，Xboard 迁移会跳过该表的创建');
    }
    
    /**
     * 检查并修复必要的表
     */
    protected function checkAndFixRequiredTables()
    {
        // 确保 v2_stat 表存在且结构正确
        if (!DB::getSchemaBuilder()->hasTable('v2_stat')) {
            $this->warn('  ⚠️  创建 v2_stat 表...');
            DB::statement("CREATE TABLE `v2_stat` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `record_at` int(11) NOT NULL,
                `record_type` char(1) NOT NULL COMMENT 'd day m month',
                `order_count` int(11) DEFAULT '0',
                `order_total` int(11) DEFAULT '0',
                `commission_count` int(11) DEFAULT '0',
                `commission_total` int(11) DEFAULT '0',
                `paid_count` int(11) DEFAULT '0',
                `paid_total` int(11) DEFAULT '0',
                `register_count` int(11) DEFAULT '0',
                `invite_count` int(11) DEFAULT '0',
                `transfer_used_total` varchar(32) DEFAULT '0',
                `created_at` int(11) NOT NULL,
                `updated_at` int(11) NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `record_at_record_type` (`record_at`, `record_type`),
                KEY `idx_record_at` (`record_at`),
                KEY `idx_record_type` (`record_type`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            $this->line('  ✅ v2_stat 表已创建');
        } else {
            // 检查并修复唯一键
            $this->checkAndFixStatTableIndex();
        }
        
        // 确保今日统计记录存在
        $todayTimestamp = strtotime(date('Y-m-d'));
        $exists = DB::table('v2_stat')
            ->where('record_at', $todayTimestamp)
            ->where('record_type', 'd')
            ->exists();
            
        if (!$exists) {
            DB::table('v2_stat')->insert([
                'record_at' => $todayTimestamp,
                'record_type' => 'd',
                'order_count' => 0,
                'order_total' => 0,
                'commission_count' => 0,
                'commission_total' => 0,
                'paid_count' => 0,
                'paid_total' => 0,
                'register_count' => 0,
                'invite_count' => 0,
                'transfer_used_total' => '0',
                'created_at' => time(),
                'updated_at' => time()
            ]);
            $this->line('  ✅ 创建今日统计记录');
        }
        
        // 创建本月统计记录
        $thisMonth = strtotime(date('Y-m-01'));
        $monthExists = DB::table('v2_stat')
            ->where('record_at', $thisMonth)
            ->where('record_type', 'm')
            ->exists();
            
        if (!$monthExists) {
            DB::table('v2_stat')->insert([
                'record_at' => $thisMonth,
                'record_type' => 'm',
                'order_count' => 0,
                'order_total' => 0,
                'commission_count' => 0,
                'commission_total' => 0,
                'paid_count' => 0,
                'paid_total' => 0,
                'register_count' => 0,
                'invite_count' => 0,
                'transfer_used_total' => '0',
                'created_at' => time(),
                'updated_at' => time()
            ]);
            $this->line('  ✅ 创建本月统计记录');
        }
        
        // 检查并创建其他必要的表
        $this->checkAndCreateMissingTables();
    }
    
    /**
     * 修复数据库配置和缓存
     */
    protected function fixDatabaseAndCache()
    {
        // 清理缓存（安全模式，跳过 Redis 错误）
        try {
            Artisan::call('config:clear');
            $this->line('  ✅ 配置缓存已清理');
        } catch (\Exception $e) {
            $this->warn('  ⚠️  配置缓存清理失败: ' . $e->getMessage());
        }
        
        try {
            Artisan::call('route:clear');
            $this->line('  ✅ 路由缓存已清理');
        } catch (\Exception $e) {
            $this->warn('  ⚠️  路由缓存清理失败: ' . $e->getMessage());
        }
        
        try {
            Artisan::call('view:clear');
            $this->line('  ✅ 视图缓存已清理');
        } catch (\Exception $e) {
            $this->warn('  ⚠️  视图缓存清理失败: ' . $e->getMessage());
        }
        
        // 尝试清理应用缓存（如果 Redis 不可用，跳过）
        try {
            Artisan::call('cache:clear');
            $this->line('  ✅ 应用缓存已清理');
        } catch (\Exception $e) {
            $this->warn('  ⚠️  应用缓存清理失败（可能使用 Redis）: ' . $e->getMessage());
            $this->line('  ℹ️  将跳过缓存清理，继续迁移');
        }
        
        // 确保管理员账号存在
        $adminCount = DB::table('v2_user')->where('is_admin', 1)->count();
        if ($adminCount === 0) {
            $firstUser = DB::table('v2_user')->orderBy('id')->first();
            if ($firstUser) {
                DB::table('v2_user')->where('id', $firstUser->id)->update(['is_admin' => 1]);
                $this->line("  ✅ 已将用户 {$firstUser->email} 设置为管理员");
            }
        } else {
            $this->line("  ✅ 已有 {$adminCount} 个管理员账号");
        }
        
        // 重新生成缓存
        try {
            Artisan::call('config:cache');
            $this->line('  ✅ 配置缓存已重建');
        } catch (\Exception $e) {
            $this->warn('  ⚠️  配置缓存重建失败: ' . $e->getMessage());
        }
        
        try {
            Artisan::call('route:cache');
            $this->line('  ✅ 路由缓存已重建');
        } catch (\Exception $e) {
            $this->warn('  ⚠️  路由缓存重建失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 清理旧的 session 数据
     */
    protected function cleanOldSessions()
    {
        try {
            if (DB::getSchemaBuilder()->hasTable('sessions')) {
                $count = DB::table('sessions')->count();
                if ($count > 0) {
                    DB::table('sessions')->truncate();
                    $this->line("  ✅ 已清理 {$count} 条旧的 session 记录");
                    $this->line('  ℹ️  迁移后请重新登录管理面板');
                } else {
                    $this->line('  ✅ Session 表为空');
                }
            }
        } catch (\Exception $e) {
            $this->warn('  ⚠️  Session 清理失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 最终修复所有关键问题（迁移的最后一步）
     */
    protected function finalFixAllIssues()
    {
        $this->line('');
        $this->line('  🔧 执行最终修复...');
        
        // 0. 强制创建 sessions 表（最重要！）
        $this->line('  0️⃣  强制创建 sessions 表...');
        try {
            if (!DB::getSchemaBuilder()->hasTable('sessions')) {
                DB::statement("CREATE TABLE `sessions` (
                    `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
                    `user_id` bigint unsigned DEFAULT NULL,
                    `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
                    `user_agent` text COLLATE utf8mb4_unicode_ci,
                    `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
                    `last_activity` int NOT NULL,
                    PRIMARY KEY (`id`),
                    KEY `sessions_user_id_index` (`user_id`),
                    KEY `sessions_last_activity_index` (`last_activity`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
                $this->line("     ✅ sessions 表已创建");
            } else {
                $this->line("     ✅ sessions 表已存在");
            }
        } catch (\Exception $e) {
            $this->error("     ❌ sessions 表创建失败: " . $e->getMessage());
        }
        
        // 0.5. 强制添加 v2_user 表缺失的字段
        $this->line('  0.5️⃣  检查并添加 v2_user 缺失字段...');
        try {
            // 检查 online_count 字段
            $hasOnlineCount = DB::select("SHOW COLUMNS FROM v2_user LIKE 'online_count'");
            if (empty($hasOnlineCount)) {
                $this->warn("     ⚠️  v2_user 缺少 online_count 字段，正在添加...");
                DB::statement("ALTER TABLE v2_user ADD COLUMN `online_count` int NULL DEFAULT 0 AFTER `device_limit`");
                $this->line("     ✅ 已添加 online_count 字段");
            } else {
                $this->line("     ✅ online_count 字段已存在");
            }
            
            // 检查 last_online_at 字段
            $hasLastOnlineAt = DB::select("SHOW COLUMNS FROM v2_user LIKE 'last_online_at'");
            if (empty($hasLastOnlineAt)) {
                DB::statement("ALTER TABLE v2_user ADD COLUMN `last_online_at` timestamp NULL AFTER `online_count`");
                $this->line("     ✅ 已添加 last_online_at 字段");
            } else {
                $this->line("     ✅ last_online_at 字段已存在");
            }
            
            // 检查 device_limit 字段
            $hasDeviceLimit = DB::select("SHOW COLUMNS FROM v2_user LIKE 'device_limit'");
            if (empty($hasDeviceLimit)) {
                DB::statement("ALTER TABLE v2_user ADD COLUMN `device_limit` int NULL AFTER `expired_at`");
                $this->line("     ✅ 已添加 device_limit 字段");
            } else {
                $this->line("     ✅ device_limit 字段已存在");
            }
        } catch (\Exception $e) {
            $this->error("     ❌ 添加字段失败: " . $e->getMessage());
        }
        
        // 1. 强制修复 v2_stat 表索引
        $this->line('  1️⃣  强制修复 v2_stat 表索引...');
        try {
            // 删除所有可能的旧索引
            $indexes = DB::select("SHOW INDEX FROM v2_stat");
            foreach ($indexes as $index) {
                if ($index->Key_name === 'record_at' && $index->Non_unique == 0) {
                    DB::statement("ALTER TABLE v2_stat DROP INDEX record_at");
                    $this->line("     ✅ 删除了旧的单字段唯一键");
                }
            }
            
            // 确保有复合唯一键
            $hasCompositeKey = false;
            foreach ($indexes as $index) {
                if ($index->Key_name === 'record_at_record_type') {
                    $hasCompositeKey = true;
                    break;
                }
            }
            
            if (!$hasCompositeKey) {
                // 先清理可能的重复数据
                $duplicates = DB::select("
                    SELECT record_at, record_type, COUNT(*) as count, MIN(id) as keep_id
                    FROM v2_stat 
                    GROUP BY record_at, record_type 
                    HAVING count > 1
                ");
                
                if (count($duplicates) > 0) {
                    foreach ($duplicates as $dup) {
                        DB::table('v2_stat')
                            ->where('record_at', $dup->record_at)
                            ->where('record_type', $dup->record_type)
                            ->where('id', '!=', $dup->keep_id)
                            ->delete();
                    }
                    $this->line("     ✅ 清理了 " . count($duplicates) . " 组重复记录");
                }
                
                // 添加复合唯一键
                DB::statement("ALTER TABLE v2_stat ADD UNIQUE KEY `record_at_record_type` (`record_at`, `record_type`)");
                $this->line("     ✅ 已添加复合唯一键");
            } else {
                $this->line("     ✅ 复合唯一键已存在");
            }
        } catch (\Exception $e) {
            $this->warn("     ⚠️  索引修复失败: " . $e->getMessage());
        }
        
        // 2. 确保今日和本月统计记录存在
        $this->line('  2️⃣  强制创建统计记录...');
        try {
            // 强制使用 UTC+8 时区并明确指定时间
            $todayDate = date('Y-m-d');
            $monthDate = date('Y-m-01');
            $today = strtotime($todayDate . ' 00:00:00');
            $thisMonth = strtotime($monthDate . ' 00:00:00');
            
            $this->line("     📅 今天: {$todayDate} (Unix: {$today})");
            $this->line("     📅 本月: {$monthDate} (Unix: {$thisMonth})");
            
            // 今日记录 - 使用事务确保成功
            DB::transaction(function () use ($today) {
                // 先删除可能存在的记录
                $deleted = DB::table('v2_stat')
                    ->where('record_at', $today)
                    ->where('record_type', 'd')
                    ->delete();
                
                if ($deleted > 0) {
                    $this->line("     🗑️  删除了 {$deleted} 条旧的今日记录");
                }
                
                // 插入新记录
                $inserted = DB::table('v2_stat')->insertGetId([
                    'record_at' => $today,
                    'record_type' => 'd',
                    'order_count' => 0,
                    'order_total' => 0,
                    'commission_count' => 0,
                    'commission_total' => 0,
                    'paid_count' => 0,
                    'paid_total' => 0,
                    'register_count' => 0,
                    'invite_count' => 0,
                    'transfer_used_total' => '0',
                    'created_at' => time(),
                    'updated_at' => time()
                ]);
                
                $this->line("     ✅ 强制创建今日统计记录 (ID: {$inserted}, Unix: {$today})");
            });
            
            // 本月记录 - 使用事务确保成功
            DB::transaction(function () use ($thisMonth) {
                // 先删除可能存在的记录
                $deleted = DB::table('v2_stat')
                    ->where('record_at', $thisMonth)
                    ->where('record_type', 'm')
                    ->delete();
                
                if ($deleted > 0) {
                    $this->line("     🗑️  删除了 {$deleted} 条旧的本月记录");
                }
                
                // 插入新记录
                $inserted = DB::table('v2_stat')->insertGetId([
                    'record_at' => $thisMonth,
                    'record_type' => 'm',
                    'order_count' => 0,
                    'order_total' => 0,
                    'commission_count' => 0,
                    'commission_total' => 0,
                    'paid_count' => 0,
                    'paid_total' => 0,
                    'register_count' => 0,
                    'invite_count' => 0,
                    'transfer_used_total' => '0',
                    'created_at' => time(),
                    'updated_at' => time()
                ]);
                
                $this->line("     ✅ 强制创建本月统计记录 (ID: {$inserted}, Unix: {$thisMonth})");
            });
            
            // 验证是否创建成功
            $todayCheck = DB::table('v2_stat')
                ->where('record_at', $today)
                ->where('record_type', 'd')
                ->first();
            
            $monthCheck = DB::table('v2_stat')
                ->where('record_at', $thisMonth)
                ->where('record_type', 'm')
                ->first();
            
            if ($todayCheck && $monthCheck) {
                $this->line("     ✅ 统计记录验证通过");
            } else {
                $this->error("     ❌ 统计记录创建失败！");
                if (!$todayCheck) $this->error("     ❌ 今日记录未找到");
                if (!$monthCheck) $this->error("     ❌ 本月记录未找到");
            }
        } catch (\Exception $e) {
            $this->error("     ❌ 统计记录创建失败: " . $e->getMessage());
            $this->error("     Stack: " . $e->getTraceAsString());
        }
        
        // 3. 再次清理所有 sessions（确保彻底）
        $this->line('  3️⃣  强制清理所有 sessions...');
        try {
            DB::table('sessions')->truncate();
            $this->line("     ✅ Sessions 已完全清空");
        } catch (\Exception $e) {
            $this->warn("     ⚠️  Session 清理失败: " . $e->getMessage());
        }
        
        // 4. 强制清理所有缓存
        $this->line('  4️⃣  强制清理所有缓存...');
        try {
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');
            $this->line("     ✅ 所有缓存已清理");
        } catch (\Exception $e) {
            $this->warn("     ⚠️  缓存清理失败（可忽略）: " . $e->getMessage());
        }
        
        // 5. 验证管理员账号
        $this->line('  5️⃣  验证管理员账号...');
        $adminCount = DB::table('v2_user')->where('is_admin', 1)->count();
        if ($adminCount > 0) {
            $this->line("     ✅ 管理员账号: {$adminCount} 个");
        } else {
            $this->warn("     ⚠️  没有管理员账号！");
            // 将第一个用户提升为管理员
            $firstUser = DB::table('v2_user')->orderBy('id')->first();
            if ($firstUser) {
                DB::table('v2_user')->where('id', $firstUser->id)->update(['is_admin' => 1]);
                $this->line("     ✅ 已将用户 #{$firstUser->id} 提升为管理员");
            }
        }
        
        // 6. 删除孤立订单（plan_id 不存在）
        $this->line('  6️⃣  清理孤立订单...');
        try {
            // 检查有多少订单的 plan_id 不存在
            $orphanedCount = DB::selectOne("
                SELECT COUNT(*) as count 
                FROM v2_order o 
                LEFT JOIN v2_plan p ON o.plan_id = p.id 
                WHERE p.id IS NULL
            ")->count;
            
            if ($orphanedCount > 0) {
                $this->warn("     ⚠️  发现 {$orphanedCount} 个孤立订单（套餐已删除）");
                
                // 获取这些订单的 ID 用于日志
                $orphanedIds = DB::select("
                    SELECT o.id, o.plan_id, o.user_id, o.status
                    FROM v2_order o 
                    LEFT JOIN v2_plan p ON o.plan_id = p.id 
                    WHERE p.id IS NULL
                    LIMIT 5
                ");
                
                $this->line("     📋 示例订单（前5个）:");
                foreach ($orphanedIds as $order) {
                    $this->line("        - Order #{$order->id}: Plan #{$order->plan_id} (不存在), User #{$order->user_id}, Status: {$order->status}");
                }
                
                // 直接删除孤立订单
                $deleted = DB::delete("
                    DELETE o FROM v2_order o
                    LEFT JOIN v2_plan p ON o.plan_id = p.id 
                    WHERE p.id IS NULL
                ");
                
                $this->line("     ✅ 已删除 {$deleted} 个孤立订单");
                
                // 验证
                $remaining = DB::selectOne("
                    SELECT COUNT(*) as count 
                    FROM v2_order o 
                    LEFT JOIN v2_plan p ON o.plan_id = p.id 
                    WHERE p.id IS NULL
                ")->count;
                
                if ($remaining > 0) {
                    $this->error("     ❌ 仍有 {$remaining} 个孤立订单未删除！");
                } else {
                    $this->line("     ✅ 所有订单的 plan 关联已验证通过");
                }
            } else {
                $this->line("     ✅ 没有孤立订单");
            }
        } catch (\Exception $e) {
            $this->error("     ❌ 清理孤立订单失败: " . $e->getMessage());
        }
        
        // 7. 测试关键 API 查询
        $this->line('  7️⃣  测试关键 API 查询...');
        try {
            // 使用与创建记录时相同的时间计算方法
            $todayDate = date('Y-m-d');
            $monthDate = date('Y-m-01');
            $today = strtotime($todayDate . ' 00:00:00');
            $thisMonth = strtotime($monthDate . ' 00:00:00');
            
            // 测试今日查询
            $todayStat = DB::table('v2_stat')
                ->where('record_at', $today)
                ->where('record_type', 'd')
                ->first();
            
            if ($todayStat) {
                $this->line("     ✅ 今日统计查询正常 (ID: {$todayStat->id})");
            } else {
                $this->error("     ❌ 今日统计记录不存在！");
                $this->error("     💡 这会导致 getStats API 返回 500 错误");
            }
            
            // 测试本月查询
            $monthStat = DB::table('v2_stat')
                ->where('record_at', $thisMonth)
                ->where('record_type', 'm')
                ->first();
            
            if ($monthStat) {
                $this->line("     ✅ 本月统计查询正常 (ID: {$monthStat->id})");
            } else {
                $this->error("     ❌ 本月统计记录不存在！");
                $this->error("     💡 这会导致 getStats API 返回 500 错误");
                
                // 如果本月记录不存在，这是严重问题，必须报错
                throw new \Exception("本月统计记录创建失败，getStats API 将无法正常工作！");
            }
        } catch (\Exception $e) {
            $this->error("     ❌ API 查询失败: " . $e->getMessage());
            throw $e; // 重新抛出异常，让迁移失败
        }
        
        // 8. 确保旧的礼品卡表已清理
        $this->line('  8️⃣  确认旧礼品卡表已清理...');
        try {
            $tablesToClean = ['v2_giftcard', 'v2_giftcard_backup'];
            $cleaned = 0;
            
            foreach ($tablesToClean as $table) {
                if (DB::getSchemaBuilder()->hasTable($table)) {
                    $this->warn("     ⚠️  发现残留的 {$table} 表，正在删除...");
                    DB::statement("DROP TABLE {$table}");
                    $cleaned++;
                }
            }
            
            if ($cleaned > 0) {
                $this->line("     ✅ 已清理 {$cleaned} 个旧礼品卡表");
            } else {
                $this->line("     ✅ 旧礼品卡表已清理");
            }
        } catch (\Exception $e) {
            $this->warn("     ⚠️  清理礼品卡表失败: " . $e->getMessage());
        }
        
        $this->line('');
        $this->line('  🎉 最终修复完成！');
    }
    
    /**
     * 检查并修复 v2_stat 表的索引
     */
    protected function checkAndFixStatTableIndex()
    {
        try {
            $this->line('  🔍 检查 v2_stat 表索引结构...');
            
            // 检查是否有旧的单字段唯一键
            $indexes = DB::select("SHOW INDEX FROM v2_stat WHERE Key_name = 'record_at'");
            
            if (count($indexes) > 0 && $indexes[0]->Non_unique == 0) {
                // 存在旧的单字段唯一键，需要删除并重建
                $this->warn('  ⚠️  发现旧的单字段唯一键，正在修复...');
                
                // 删除旧的唯一键
                DB::statement("ALTER TABLE v2_stat DROP INDEX record_at");
                $this->line('  ✅ 已删除旧的唯一键');
                
                // 创建新的复合唯一键
                try {
                    DB::statement("ALTER TABLE v2_stat ADD UNIQUE KEY `record_at_record_type` (`record_at`, `record_type`)");
                    $this->line('  ✅ 已创建复合唯一键 (record_at, record_type)');
                } catch (\Exception $e) {
                    if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                        $this->line('  ℹ️  复合唯一键已存在');
                    } else {
                        throw $e;
                    }
                }
            } else {
                // 检查是否已有复合唯一键
                $compositeIndex = DB::select("SHOW INDEX FROM v2_stat WHERE Key_name = 'record_at_record_type'");
                if (empty($compositeIndex)) {
                    $this->warn('  ⚠️  缺少复合唯一键，正在添加...');
                    // 添加复合唯一键
                    try {
                        DB::statement("ALTER TABLE v2_stat ADD UNIQUE KEY `record_at_record_type` (`record_at`, `record_type`)");
                        $this->line('  ✅ 已添加复合唯一键');
                    } catch (\Exception $e) {
                        if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                            // 存在重复数据，需要清理
                            $this->warn('  ⚠️  发现重复数据，正在清理...');
                            $cleaned = $this->cleanDuplicateStatRecords();
                            
                            if ($cleaned > 0) {
                                // 再次尝试添加唯一键
                                DB::statement("ALTER TABLE v2_stat ADD UNIQUE KEY `record_at_record_type` (`record_at`, `record_type`)");
                                $this->line('  ✅ 清理 {$cleaned} 条重复数据后已添加复合唯一键');
                            }
                        } else {
                            throw $e;
                        }
                    }
                } else {
                    $this->line('  ✅ 复合唯一键结构正确');
                }
            }
        } catch (\Exception $e) {
            $this->error('  ❌ 索引修复失败: ' . $e->getMessage());
            $this->warn('  💡 请手动运行: php fix_stat_table.php');
        }
    }
    
    /**
     * 清理重复的统计记录
     */
    protected function cleanDuplicateStatRecords()
    {
        // 查找重复记录
        $duplicates = DB::select("
            SELECT record_at, record_type, COUNT(*) as count, MIN(id) as keep_id
            FROM v2_stat 
            GROUP BY record_at, record_type 
            HAVING count > 1
        ");
        
        $totalCleaned = 0;
        
        if (count($duplicates) > 0) {
            $this->line("  🧹 发现 " . count($duplicates) . " 组重复记录");
            
            foreach ($duplicates as $dup) {
                // 删除除了最小ID之外的所有重复记录
                $deleted = DB::table('v2_stat')
                    ->where('record_at', $dup->record_at)
                    ->where('record_type', $dup->record_type)
                    ->where('id', '!=', $dup->keep_id)
                    ->delete();
                
                $totalCleaned += $deleted;
                $this->line("  ✅ 清理了 {$deleted} 条重复记录 (record_at={$dup->record_at}, type={$dup->record_type})");
            }
        }
        
        return $totalCleaned;
    }
    
    /**
     * 检查并创建缺失的表
     */
    protected function checkAndCreateMissingTables()
    {
        // 检查 v2_settings 表（仅检查，不迁移配置）
        if (!DB::getSchemaBuilder()->hasTable('v2_settings')) {
            $this->warn('  ⚠️  创建 v2_settings 表...');
            DB::statement("CREATE TABLE `v2_settings` (
                `name` varchar(255) NOT NULL,
                `value` longtext,
                PRIMARY KEY (`name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            $this->line('  ✅ v2_settings 表已创建（配置迁移将在后续步骤执行）');
        }
        
        // 检查 sessions 表
        if (!DB::getSchemaBuilder()->hasTable('sessions')) {
            $this->warn('  ⚠️  创建 sessions 表...');
            DB::statement("CREATE TABLE `sessions` (
                `id` varchar(255) NOT NULL,
                `user_id` int(11) DEFAULT NULL,
                `ip_address` varchar(45) DEFAULT NULL,
                `user_agent` text,
                `payload` text NOT NULL,
                `last_activity` int(11) NOT NULL,
                PRIMARY KEY (`id`),
                KEY `sessions_user_id_index` (`user_id`),
                KEY `sessions_last_activity_index` (`last_activity`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
            $this->line('  ✅ sessions 表已创建');
        }
        
        // 检查其他重要的表
        $otherTables = [
            'v2_stat_user' => '用户统计表',
            'v2_stat_server' => '节点统计表',
            'v2_commission_log' => '佣金日志表'
        ];
        
        foreach ($otherTables as $table => $description) {
            if (!DB::getSchemaBuilder()->hasTable($table)) {
                $this->line("  ℹ️  {$description} ({$table}) 将由 Xboard 迁移自动创建");
            }
        }
    }
    
    /**
     * 验证系统表
     */
    protected function verifySystemTables()
    {
        $requiredTables = [
            'v2_stat' => '统计表',
            'v2_stat_server' => '节点统计表',
            'v2_stat_user' => '用户统计表',
            'v2_commission_log' => '佣金日志表',
            'v2_settings' => '设置表'
        ];
        
        $this->info('');
        $this->info('  📊 数据库表验证:');
        foreach ($requiredTables as $table => $description) {
            if (DB::getSchemaBuilder()->hasTable($table)) {
                $count = DB::table($table)->count();
                $this->line("  ✅ {$description} ({$table}): {$count} 条记录");
            } else {
                $this->warn("  ⚠️  {$description} ({$table}) 不存在");
            }
        }
        
        // 验证管理员账号
        $adminCount = DB::table('v2_user')->where('is_admin', 1)->count();
        if ($adminCount > 0) {
            $this->info("  👥 管理员数量: {$adminCount}");
        } else {
            $this->warn('  ⚠️  没有管理员账号，请确认数据迁移正确');
        }
        
        // 验证订单数据
        if (DB::getSchemaBuilder()->hasTable('v2_order')) {
            $orderCount = DB::table('v2_order')->count();
            $this->info("  📦 订单总数: {$orderCount}");
            
            // 检查订单表必要字段
            $orderColumns = ['id', 'user_id', 'plan_id', 'trade_no', 'total_amount', 'status'];
            $hasAllColumns = true;
            foreach ($orderColumns as $col) {
                if (!DB::getSchemaBuilder()->hasColumn('v2_order', $col)) {
                    $this->warn("  ⚠️  订单表缺少字段: {$col}");
                    $hasAllColumns = false;
                }
            }
            
            if ($hasAllColumns) {
                $this->line('  ✅ 订单表结构完整');
            }
        }
        
        // 最终检查：尝试模拟 getStats 查询
        $this->line('');
        $this->line('  🔍 测试统计API查询...');
        try {
            $today = strtotime(date('Y-m-d'));
            $stat = DB::table('v2_stat')
                ->where('record_at', $today)
                ->where('record_type', 'd')
                ->first();
            
            if ($stat) {
                $this->info('  ✅ 统计API查询正常');
            } else {
                $this->warn('  ⚠️  今日统计记录不存在');
            }
        } catch (\Exception $e) {
            $this->error('  ❌ 统计API查询失败: ' . $e->getMessage());
            $this->warn('  💡 这可能导致管理面板 500 错误');
        }
    }
}
