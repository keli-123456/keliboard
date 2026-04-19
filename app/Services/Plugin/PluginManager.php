<?php

namespace App\Services\Plugin;

use App\Models\Plugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PluginManager
{
    private const MAX_PLUGIN_ARCHIVE_ENTRIES = 2000;
    private const MAX_PLUGIN_ARCHIVE_UNCOMPRESSED_SIZE = 104857600; // 100 MiB

    protected string $pluginPath;
    protected array $loadedPlugins = [];
    protected bool $pluginsInitialized = false;
    protected array $configTypesCache = [];

    public function __construct()
    {
        $this->pluginPath = base_path('plugins');
    }

    /**
     * 获取插件的命名空间
     */
    public function getPluginNamespace(string $pluginCode): string
    {
        return 'Plugin\\' . Str::studly($pluginCode);
    }

    /**
     * 获取插件的基础路径
     */
    public function getPluginPath(string $pluginCode): string
    {
        return $this->pluginPath . '/' . Str::studly($pluginCode);
    }

    /**
     * 加载插件类
     */
    protected function loadPlugin(string $pluginCode): ?AbstractPlugin
    {
        if (isset($this->loadedPlugins[$pluginCode])) {
            return $this->loadedPlugins[$pluginCode];
        }

        $pluginClass = $this->getPluginNamespace($pluginCode) . '\\Plugin';

        if (!class_exists($pluginClass)) {
            $pluginFile = $this->getPluginPath($pluginCode) . '/Plugin.php';
            if (!File::exists($pluginFile)) {
                Log::warning("Plugin class file not found: {$pluginFile}");
                Plugin::query()->where('code', $pluginCode)->delete();
                return null;
            }
            require_once $pluginFile;
        }

        if (!class_exists($pluginClass)) {
            Log::error("Plugin class not found: {$pluginClass}");
            return null;
        }

        $plugin = new $pluginClass($pluginCode);
        $this->loadedPlugins[$pluginCode] = $plugin;

        return $plugin;
    }

    /**
     * 注册插件的服务提供者
     */
    protected function registerServiceProvider(string $pluginCode): void
    {
        $providerClass = $this->getPluginNamespace($pluginCode) . '\\Providers\\PluginServiceProvider';

        if (class_exists($providerClass)) {
            app()->register($providerClass);
        }
    }

    /**
     * 加载插件的路由
     */
    protected function loadRoutes(string $pluginCode): void
    {
        $routesPath = $this->getPluginPath($pluginCode) . '/routes';
        if (File::exists($routesPath)) {
            $webRouteFile = $routesPath . '/web.php';
            $apiRouteFile = $routesPath . '/api.php';
            if (File::exists($webRouteFile)) {
                Route::middleware('web')
                    ->namespace($this->getPluginNamespace($pluginCode) . '\\Controllers')
                    ->group(function () use ($webRouteFile) {
                        require $webRouteFile;
                    });
            }
            if (File::exists($apiRouteFile)) {
                Route::middleware('api')
                    ->namespace($this->getPluginNamespace($pluginCode) . '\\Controllers')
                    ->group(function () use ($apiRouteFile) {
                        require $apiRouteFile;
                    });
            }
        }
    }

    /**
     * 加载插件的视图
     */
    protected function loadViews(string $pluginCode): void
    {
        $viewsPath = $this->getPluginPath($pluginCode) . '/resources/views';
        if (File::exists($viewsPath)) {
            View::addNamespace(Str::studly($pluginCode), $viewsPath);
            return;
        }
    }

    /**
     * 注册插件命令
     */
    protected function registerPluginCommands(string $pluginCode, AbstractPlugin $pluginInstance): void
    {
        try {
            // 调用插件的命令注册方法
            $pluginInstance->registerCommands();
        } catch (\Exception $e) {
            Log::error("Failed to register commands for plugin '{$pluginCode}': " . $e->getMessage());
        }
    }

    /**
     * 安装插件
     */
    public function install(string $pluginCode): bool
    {
        $configFile = $this->getPluginPath($pluginCode) . '/config.json';

        if (!File::exists($configFile)) {
            throw new \Exception('Plugin config file not found');
        }

        $config = json_decode(File::get($configFile), true);
        if (!$this->validateConfig($config)) {
            throw new \Exception('Invalid plugin config');
        }

        // 检查插件是否已安装
        if (Plugin::where('code', $pluginCode)->exists()) {
            throw new \Exception('Plugin already installed');
        }

        // 检查依赖
        $dependencyError = null;
        if (!$this->checkDependencies($config['require'] ?? [], $dependencyError)) {
            throw new \Exception($dependencyError ?: 'Dependencies not satisfied');
        }

        // 运行数据库迁移
        $this->runMigrations(pluginCode: $pluginCode);

        DB::beginTransaction();
        try {
            // 提取配置默认值
            $defaultValues = $this->extractDefaultConfig($config);

            // 创建插件实例
            $plugin = $this->loadPlugin($pluginCode);

            // 注册到数据库
            Plugin::create([
                'code' => $pluginCode,
                'name' => $config['name'],
                'version' => $config['version'],
                'type' => $config['type'] ?? Plugin::TYPE_FEATURE,
                'is_enabled' => false,
                'config' => json_encode($defaultValues),
                'installed_at' => now(),
            ]);

            // 运行插件安装方法
            if (method_exists($plugin, 'install')) {
                $plugin->install();
            }

            // 发布插件资源
            $this->publishAssets($pluginCode);

            DB::commit();
            return true;
        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            throw $e;
        }
    }

    /**
     * 提取插件默认配置
     */
    protected function extractDefaultConfig(array $config): array
    {
        $defaultValues = [];
        if (isset($config['config']) && is_array($config['config'])) {
            foreach ($config['config'] as $key => $item) {
                if (is_array($item)) {
                    $defaultValues[$key] = $item['default'] ?? null;
                } else {
                    $defaultValues[$key] = $item;
                }
            }
        }
        return $defaultValues;
    }

    /**
     * 运行插件数据库迁移
     */
    protected function runMigrations(string $pluginCode): void
    {
        $migrationsPath = $this->getPluginPath($pluginCode) . '/database/migrations';

        if (File::exists($migrationsPath)) {
            Artisan::call('migrate', [
                '--path' => "plugins/" . Str::studly($pluginCode) . "/database/migrations",
                '--force' => true
            ]);
        }
    }

    /**
     * 回滚插件数据库迁移
     */
    protected function runMigrationsRollback(string $pluginCode): void
    {
        $migrationsPath = $this->getPluginPath($pluginCode) . '/database/migrations';

        if (File::exists($migrationsPath)) {
            Artisan::call('migrate:rollback', [
                '--path' => "plugins/" . Str::studly($pluginCode) . "/database/migrations",
                '--force' => true
            ]);
        }
    }

    /**
     * 发布插件资源
     */
    protected function publishAssets(string $pluginCode): void
    {
        $assetsPath = $this->getPluginPath($pluginCode) . '/resources/assets';
        if (File::exists($assetsPath)) {
            $publishPath = public_path('plugins/' . $pluginCode);
            File::ensureDirectoryExists($publishPath);
            File::copyDirectory($assetsPath, $publishPath);
        }
    }

    /**
     * 验证配置文件
     */
    protected function validateConfig(array $config): bool
    {
        $requiredFields = [
            'name',
            'code',
            'version',
            'description',
            'author'
        ];

        foreach ($requiredFields as $field) {
            if (!isset($config[$field]) || empty($config[$field])) {
                return false;
            }
        }

        // 验证插件代码格式
        if (!preg_match('/^[a-z0-9_]+$/', $config['code'])) {
            return false;
        }

        // 验证版本号格式
        if (!preg_match('/^\d+\.\d+\.\d+$/', $config['version'])) {
            return false;
        }

        // 验证插件类型
        if (isset($config['type'])) {
            $validTypes = ['feature', 'payment'];
            if (!in_array($config['type'], $validTypes)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 启用插件
     */
    public function enable(string $pluginCode): bool
    {
        $plugin = $this->loadPlugin($pluginCode);

        if (!$plugin) {
            Plugin::where('code', $pluginCode)->delete();
            throw new \Exception('Plugin not found: ' . $pluginCode);
        }

        // 获取插件配置
        $dbPlugin = Plugin::query()
            ->where('code', $pluginCode)
            ->first();

        if ($dbPlugin && !empty($dbPlugin->config)) {
            $values = json_decode($dbPlugin->config, true) ?: [];
            $values = $this->castConfigValuesByType($pluginCode, $values);
            $plugin->setConfig($values);
        }

        // 注册服务提供者
        $this->registerServiceProvider($pluginCode);

        // 加载路由
        $this->loadRoutes($pluginCode);

        // 加载视图
        $this->loadViews($pluginCode);

        // 更新数据库状态
        Plugin::query()
            ->where('code', $pluginCode)
            ->update([
                'is_enabled' => true,
                'updated_at' => now(),
            ]);
        // 初始化插件
        $plugin->boot();

        return true;
    }

    /**
     * 禁用插件
     */
    public function disable(string $pluginCode): bool
    {
        $plugin = $this->loadPlugin($pluginCode);
        if (!$plugin) {
            throw new \Exception('Plugin not found');
        }

        Plugin::query()
            ->where('code', $pluginCode)
            ->update([
                'is_enabled' => false,
                'updated_at' => now(),
            ]);

        $plugin->cleanup();

        return true;
    }

    /**
     * 卸载插件
     */
    public function uninstall(string $pluginCode): bool
    {
        $this->disable($pluginCode);
        $this->runMigrationsRollback($pluginCode);
        Plugin::query()->where('code', $pluginCode)->delete();

        return true;
    }

    /**
     * 删除插件
     *
     * @param string $pluginCode
     * @return bool
     * @throws \Exception
     */
    public function delete(string $pluginCode): bool
    {
        // 先卸载插件
        if (Plugin::where('code', $pluginCode)->exists()) {
            $this->uninstall($pluginCode);
        }

        $pluginPath = $this->getPluginPath($pluginCode);
        if (!File::exists($pluginPath)) {
            throw new \Exception('插件不存在');
        }

        // 删除插件目录
        File::deleteDirectory($pluginPath);

        return true;
    }

    /**
     * 检查依赖关系
     */
    protected function checkDependencies(array $requires, ?string &$failureReason = null): bool
    {
        $failureReason = null;

        foreach ($requires as $package => $constraint) {
            $packageName = strtolower(trim((string) $package));
            $versionConstraint = trim((string) $constraint);

            if ($packageName === '' || $versionConstraint === '' || $versionConstraint === '*') {
                continue;
            }

            if ($packageName === 'xboard') {
                $currentVersion = $this->resolveCurrentXboardVersion();
                if ($currentVersion === null) {
                    $failureReason = 'Unable to determine current xboard version';
                    return false;
                }

                if (!$this->satisfiesVersionConstraint($currentVersion, $versionConstraint)) {
                    $failureReason = sprintf(
                        'xboard version constraint not satisfied: required %s, current %s',
                        $versionConstraint,
                        $currentVersion
                    );
                    return false;
                }
                continue;
            }

            if ($packageName === 'php') {
                if (!$this->satisfiesVersionConstraint(PHP_VERSION, $versionConstraint)) {
                    $failureReason = sprintf(
                        'php version constraint not satisfied: required %s, current %s',
                        $versionConstraint,
                        PHP_VERSION
                    );
                    return false;
                }
                continue;
            }

            $pluginCode = null;
            if (str_starts_with($packageName, 'plugin:')) {
                $pluginCode = trim(substr($packageName, strlen('plugin:')));
            } elseif (preg_match('/^[a-z0-9_]+$/', $packageName)) {
                $pluginCode = $packageName;
            }

            if ($pluginCode !== null && $pluginCode !== '') {
                if (!$this->checkPluginDependency($pluginCode, $versionConstraint, $failureReason)) {
                    return false;
                }
                continue;
            }

            $failureReason = sprintf('Unsupported dependency package: %s', $packageName);
            return false;
        }

        return true;
    }

    private function checkPluginDependency(string $pluginCode, string $constraint, ?string &$failureReason = null): bool
    {
        try {
            $plugin = Plugin::query()->where('code', $pluginCode)->first();
        } catch (\Throwable $e) {
            $failureReason = sprintf('Failed to load plugin dependency %s: %s', $pluginCode, $e->getMessage());
            return false;
        }

        if (!$plugin) {
            $failureReason = sprintf('Plugin dependency not installed: %s', $pluginCode);
            return false;
        }

        $installedVersion = (string) $plugin->version;
        if (!$this->satisfiesVersionConstraint($installedVersion, $constraint)) {
            $failureReason = sprintf(
                'Plugin dependency version constraint not satisfied: %s requires %s, current %s',
                $pluginCode,
                $constraint,
                $installedVersion
            );
            return false;
        }

        return true;
    }

    private function resolveCurrentXboardVersion(): ?string
    {
        $configuredVersion = trim((string) config('app.version', ''));
        if ($configuredVersion !== '') {
            $normalized = $this->normalizeVersion($configuredVersion);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function satisfiesVersionConstraint(string $currentVersion, string $constraint): bool
    {
        $normalizedCurrentVersion = $this->normalizeVersion($currentVersion);
        if ($normalizedCurrentVersion === null) {
            return false;
        }

        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        $orGroups = preg_split('/\s*\|\|\s*/', $constraint) ?: [];
        foreach ($orGroups as $group) {
            $group = trim($group);
            if ($group === '') {
                continue;
            }
            if ($this->evaluateConstraintGroup($normalizedCurrentVersion, $group)) {
                return true;
            }
        }

        return false;
    }

    private function evaluateConstraintGroup(string $currentVersion, string $group): bool
    {
        if (preg_match('/^\s*([^\s]+)\s*-\s*([^\s]+)\s*$/', $group, $matches)) {
            $lower = $this->normalizeVersion($matches[1]);
            $upper = $this->normalizeVersion($matches[2]);
            if ($lower === null || $upper === null) {
                return false;
            }
            return version_compare($currentVersion, $lower, '>=') && version_compare($currentVersion, $upper, '<=');
        }

        $tokens = preg_split('/\s*,\s*|\s+/', $group) ?: [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '' || $token === '*' || strcasecmp($token, 'x') === 0) {
                continue;
            }

            $comparators = $this->buildComparatorsFromToken($token);
            if (empty($comparators)) {
                return false;
            }

            foreach ($comparators as $comparator) {
                if (!$this->compareWithComparator($currentVersion, $comparator[0], $comparator[1])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return array<int, array{0:string,1:string}>
     */
    private function buildComparatorsFromToken(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            return [];
        }

        if ($token[0] === '^') {
            return $this->buildCaretComparators(substr($token, 1));
        }

        if ($token[0] === '~') {
            return $this->buildTildeComparators(substr($token, 1));
        }

        if (preg_match('/^[vV]?[0-9xX\*]+(?:\.[0-9xX\*]+){0,2}$/', $token) && preg_match('/[xX\*]/', $token)) {
            return $this->buildWildcardComparators($token);
        }

        if (!preg_match('/^(>=|<=|>|<|=|==|!=)?\s*(.+)$/', $token, $matches)) {
            return [];
        }

        $operator = $matches[1] ?: '=';
        if ($operator === '==') {
            $operator = '=';
        }

        $targetVersion = $this->normalizeVersion($matches[2]);
        if ($targetVersion === null) {
            return [];
        }

        return [[$operator, $targetVersion]];
    }

    /**
     * @return array<int, array{0:string,1:string}>
     */
    private function buildCaretComparators(string $rawVersion): array
    {
        [$parts, $partCount] = $this->parseNumericVersionParts($rawVersion);
        if ($parts === null) {
            return [];
        }

        [$major, $minor, $patch] = $parts;
        $lower = $this->composeVersion($major, $minor, $patch);

        if ($partCount === 1) {
            $upper = $major === 0
                ? $this->composeVersion(1, 0, 0)
                : $this->composeVersion($major + 1, 0, 0);
        } elseif ($major > 0) {
            $upper = $this->composeVersion($major + 1, 0, 0);
        } elseif ($minor > 0) {
            $upper = $this->composeVersion(0, $minor + 1, 0);
        } else {
            $upper = $this->composeVersion(0, 0, $patch + 1);
        }

        return [
            ['>=', $lower],
            ['<', $upper],
        ];
    }

    /**
     * @return array<int, array{0:string,1:string}>
     */
    private function buildTildeComparators(string $rawVersion): array
    {
        [$parts, $partCount] = $this->parseNumericVersionParts($rawVersion);
        if ($parts === null) {
            return [];
        }

        [$major, $minor, $patch] = $parts;
        $lower = $this->composeVersion($major, $minor, $patch);

        if ($partCount <= 1) {
            $upper = $this->composeVersion($major + 1, 0, 0);
        } else {
            $upper = $this->composeVersion($major, $minor + 1, 0);
        }

        return [
            ['>=', $lower],
            ['<', $upper],
        ];
    }

    /**
     * @return array<int, array{0:string,1:string}>
     */
    private function buildWildcardComparators(string $rawVersion): array
    {
        $normalized = strtolower(ltrim(trim($rawVersion), 'v'));
        if ($normalized === '' || $normalized === '*' || $normalized === 'x') {
            return [['*', '*']];
        }

        $parts = explode('.', $normalized);
        if (count($parts) > 3) {
            return [];
        }
        while (count($parts) < 3) {
            $parts[] = 'x';
        }

        $wildcardIndex = null;
        foreach ($parts as $index => $part) {
            if ($part === 'x' || $part === '*') {
                $wildcardIndex = $index;
                break;
            }
            if (!ctype_digit($part)) {
                return [];
            }
        }

        if ($wildcardIndex === null) {
            $exact = $this->normalizeVersion(implode('.', $parts));
            return $exact === null ? [] : [['=', $exact]];
        }

        if ($wildcardIndex === 0) {
            return [['*', '*']];
        }

        $major = (int) $parts[0];
        $minor = ($parts[1] === 'x' || $parts[1] === '*') ? 0 : (int) $parts[1];
        $patch = ($parts[2] === 'x' || $parts[2] === '*') ? 0 : (int) $parts[2];

        $lower = $this->composeVersion($major, $minor, $patch);
        $upper = $wildcardIndex === 1
            ? $this->composeVersion($major + 1, 0, 0)
            : $this->composeVersion($major, $minor + 1, 0);

        return [
            ['>=', $lower],
            ['<', $upper],
        ];
    }

    private function compareWithComparator(string $currentVersion, string $operator, string $targetVersion): bool
    {
        if ($operator === '*' || $operator === '') {
            return true;
        }

        $normalizedTargetVersion = $this->normalizeVersion($targetVersion);
        if ($normalizedTargetVersion === null) {
            return false;
        }

        return match ($operator) {
            '>' => version_compare($currentVersion, $normalizedTargetVersion, '>'),
            '>=' => version_compare($currentVersion, $normalizedTargetVersion, '>='),
            '<' => version_compare($currentVersion, $normalizedTargetVersion, '<'),
            '<=' => version_compare($currentVersion, $normalizedTargetVersion, '<='),
            '=' => version_compare($currentVersion, $normalizedTargetVersion, '=='),
            '!=' => version_compare($currentVersion, $normalizedTargetVersion, '!='),
            default => false,
        };
    }

    /**
     * @return array{0: array{0:int,1:int,2:int}|null, 1:int}
     */
    private function parseNumericVersionParts(string $rawVersion): array
    {
        $rawVersion = trim(ltrim($rawVersion, 'vV'));
        if ($rawVersion === '') {
            return [null, 0];
        }

        $rawVersion = preg_split('/[-+]/', $rawVersion)[0] ?? '';
        $segments = explode('.', $rawVersion);
        if (count($segments) > 3) {
            return [null, 0];
        }

        $partCount = count($segments);
        foreach ($segments as $segment) {
            if ($segment === '' || !ctype_digit($segment)) {
                return [null, 0];
            }
        }

        while (count($segments) < 3) {
            $segments[] = '0';
        }

        return [[(int) $segments[0], (int) $segments[1], (int) $segments[2]], $partCount];
    }

    private function composeVersion(int $major, int $minor, int $patch): string
    {
        return sprintf('%d.%d.%d', $major, $minor, $patch);
    }

    private function normalizeVersion(string $version): ?string
    {
        $version = trim(ltrim($version, 'vV'));
        if ($version === '') {
            return null;
        }

        if (!preg_match('/^(\d+(?:\.\d+){0,2})([-+][0-9A-Za-z.-]+)?$/', $version, $matches)) {
            return null;
        }

        $core = explode('.', $matches[1]);
        while (count($core) < 3) {
            $core[] = '0';
        }

        $suffix = $matches[2] ?? '';
        return implode('.', $core) . $suffix;
    }

    /**
     * 升级插件
     *
     * @param string $pluginCode
     * @return bool
     * @throws \Exception
     */
    public function update(string $pluginCode): bool
    {
        $dbPlugin = Plugin::where('code', $pluginCode)->first();
        if (!$dbPlugin) {
            throw new \Exception('Plugin not installed: ' . $pluginCode);
        }

        // 获取插件配置文件中的最新版本
        $configFile = $this->getPluginPath($pluginCode) . '/config.json';
        if (!File::exists($configFile)) {
            throw new \Exception('Plugin config file not found');
        }

        $config = json_decode(File::get($configFile), true);
        if (!$config || !isset($config['version'])) {
            throw new \Exception('Invalid plugin config or missing version');
        }

        $newVersion = $config['version'];
        $oldVersion = $dbPlugin->version;

        if (version_compare($newVersion, $oldVersion, '<=')) {
            throw new \Exception('Plugin is already up to date');
        }

        $this->disable($pluginCode);
        $this->runMigrations($pluginCode);

        $plugin = $this->loadPlugin($pluginCode);
            if ($plugin) {
                if (!empty($dbPlugin->config)) {
                    $values = json_decode($dbPlugin->config, true) ?: [];
                    $values = $this->castConfigValuesByType($pluginCode, $values);
                    $plugin->setConfig($values);
                }

                $plugin->update($oldVersion, $newVersion);
            }

        $dbPlugin->update([
            'version' => $newVersion,
            'updated_at' => now(),
        ]);

        $this->enable($pluginCode);

        return true;
    }

    /**
     * 上传插件
     *
     * @param \Illuminate\Http\UploadedFile $file
     * @return bool
     * @throws \Exception
     */
    public function upload($file): bool
    {
        $tmpPath = storage_path('tmp/plugins');
        if (!File::exists($tmpPath)) {
            File::makeDirectory($tmpPath, 0755, true);
        }

        $extractPath = $tmpPath . '/' . uniqid('plugin_', true);
        File::makeDirectory($extractPath, 0755, true);
        $zip = new \ZipArchive();

        if ($zip->open($file->path()) !== true) {
            throw new \Exception('无法打开插件包文件');
        }

        try {
            $this->assertArchiveIsSafeToExtract($zip);
            if (!$zip->extractTo($extractPath)) {
                throw new \Exception('插件包解压失败');
            }
        } finally {
            $zip->close();
        }

        try {
            $configFile = File::glob($extractPath . '/*/config.json');
            if (empty($configFile)) {
                $configFile = File::glob($extractPath . '/config.json');
            }

            if (empty($configFile)) {
                throw new \Exception('插件包格式错误：缺少配置文件');
            }

            $pluginPath = dirname(reset($configFile));
            $pluginRealPath = realpath($pluginPath);
            $extractRealPath = realpath($extractPath);
            if (!$pluginRealPath || !$extractRealPath || !$this->isPathInside($pluginRealPath, $extractRealPath)) {
                throw new \Exception('插件包路径非法');
            }

            $config = json_decode(File::get($pluginPath . '/config.json'), true);
            if (!$this->validateConfig($config)) {
                throw new \Exception('插件配置文件格式错误');
            }

            $targetPath = $this->pluginPath . '/' . Str::studly($config['code']);
            if (File::exists($targetPath)) {
                $installedConfigPath = $targetPath . '/config.json';
                if (!File::exists($installedConfigPath)) {
                    throw new \Exception('已安装插件缺少配置文件，无法判断是否可升级');
                }
                $installedConfig = json_decode(File::get($installedConfigPath), true);

                $oldVersion = $installedConfig['version'] ?? null;
                $newVersion = $config['version'] ?? null;
                if (!$oldVersion || !$newVersion) {
                    throw new \Exception('插件缺少版本号，无法判断是否可升级');
                }
                if (version_compare($newVersion, $oldVersion, '<=')) {
                    throw new \Exception('上传插件版本不高于已安装版本，无法升级');
                }
            }

            $stagedTargetPath = $this->pluginPath . '/.__upload_staging_' . uniqid('', true);
            if (!File::copyDirectory($pluginPath, $stagedTargetPath)) {
                throw new \Exception('插件复制到暂存目录失败');
            }

            $backupPath = null;
            $committed = false;
            try {
                if (File::exists($targetPath)) {
                    $backupPath = $this->pluginPath . '/.__upload_backup_' . uniqid('', true);
                    if (!$this->renameDirectory($targetPath, $backupPath)) {
                        throw new \Exception('插件备份失败');
                    }
                }

                if (!$this->renameDirectory($stagedTargetPath, $targetPath)) {
                    if (!File::copyDirectory($stagedTargetPath, $targetPath)) {
                        throw new \Exception('插件落盘失败');
                    }
                    File::deleteDirectory($stagedTargetPath);
                }

                if (Plugin::where('code', $config['code'])->exists()) {
                    $result = $this->update($config['code']);
                    $committed = true;
                    return $result;
                }

                $committed = true;
                return true;
            } catch (\Throwable $e) {
                if ($backupPath && File::exists($backupPath)) {
                    File::deleteDirectory($targetPath);
                    if (!$this->renameDirectory($backupPath, $targetPath)) {
                        Log::error("Failed to rollback plugin directory from backup: {$backupPath} -> {$targetPath}");
                    }
                } elseif ($backupPath === null) {
                    File::deleteDirectory($targetPath);
                }
                throw $e;
            } finally {
                File::deleteDirectory($stagedTargetPath);
                if ($committed && $backupPath && File::exists($backupPath)) {
                    File::deleteDirectory($backupPath);
                }
            }
        } finally {
            File::deleteDirectory($extractPath);
        }
    }

    /**
     * 在解压前校验 ZIP 条目，防止 Zip Slip 等路径穿越写文件。
     */
    private function assertArchiveIsSafeToExtract(\ZipArchive $zip): void
    {
        $numFiles = $zip->numFiles;
        if ($numFiles <= 0) {
            throw new \Exception('插件包为空');
        }
        if ($numFiles > self::MAX_PLUGIN_ARCHIVE_ENTRIES) {
            throw new \Exception('插件包文件数量超过限制');
        }

        $totalSize = 0;
        for ($index = 0; $index < $numFiles; $index++) {
            $entryName = (string) $zip->getNameIndex($index);
            if ($this->normalizeArchiveEntryPath($entryName) === null) {
                throw new \Exception('插件包包含非法路径条目');
            }

            $stat = $zip->statIndex($index);
            $entrySize = (is_array($stat) && isset($stat['size']) && is_numeric($stat['size']))
                ? (int) $stat['size']
                : 0;
            if ($entrySize < 0) {
                throw new \Exception('插件包包含非法文件大小');
            }
            $totalSize += $entrySize;
            if ($totalSize > self::MAX_PLUGIN_ARCHIVE_UNCOMPRESSED_SIZE) {
                throw new \Exception('插件包解压总大小超过限制');
            }

            if ($this->isSymlinkEntry($zip, $index)) {
                throw new \Exception('插件包包含符号链接，已拒绝');
            }
        }
    }

    private function normalizeArchiveEntryPath(string $entryName): ?string
    {
        if ($entryName === '' || str_contains($entryName, "\0")) {
            return null;
        }

        $path = str_replace('\\', '/', $entryName);

        if (str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:\//', $path)) {
            return null;
        }

        $parts = explode('/', $path);
        $normalized = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                return null;
            }
            $normalized[] = $part;
        }

        if (empty($normalized)) {
            return null;
        }

        return implode('/', $normalized);
    }

    private function isSymlinkEntry(\ZipArchive $zip, int $index): bool
    {
        if (!method_exists($zip, 'getExternalAttributesIndex')) {
            return false;
        }

        $opsys = null;
        $attributes = null;
        if (!$zip->getExternalAttributesIndex($index, $opsys, $attributes)) {
            return false;
        }

        if ($opsys !== \ZipArchive::OPSYS_UNIX || !is_numeric($attributes)) {
            return false;
        }

        $mode = ((int) $attributes >> 16) & 0170000;
        return $mode === 0120000;
    }

    private function isPathInside(string $candidatePath, string $basePath): bool
    {
        $candidate = rtrim(str_replace('\\', '/', $candidatePath), '/');
        $base = rtrim(str_replace('\\', '/', $basePath), '/');

        return $candidate === $base || str_starts_with($candidate, $base . '/');
    }

    private function renameDirectory(string $from, string $to): bool
    {
        clearstatcache(true, $from);
        clearstatcache(true, $to);
        if (!is_dir($from)) {
            return false;
        }
        return @rename($from, $to);
    }

    /**
     * Initializes all enabled plugins from the database.
     * This method ensures that plugins are loaded, and their routes, views,
     * and service providers are registered only once per request cycle.
     */
    public function initializeEnabledPlugins(): void
    {
        if ($this->pluginsInitialized) {
            return;
        }

        $enabledPlugins = Plugin::where('is_enabled', true)->get();

        foreach ($enabledPlugins as $dbPlugin) {
            try {
                $pluginCode = $dbPlugin->code;

                $pluginInstance = $this->loadPlugin($pluginCode);
                if (!$pluginInstance) {
                    continue;
                }

                if (!empty($dbPlugin->config)) {
                    $values = json_decode($dbPlugin->config, true) ?: [];
                    $values = $this->castConfigValuesByType($pluginCode, $values);
                    $pluginInstance->setConfig($values);
                }

                $this->registerServiceProvider($pluginCode);
                $this->loadRoutes($pluginCode);
                $this->loadViews($pluginCode);
                $this->registerPluginCommands($pluginCode, $pluginInstance);

                $pluginInstance->boot();

            } catch (\Exception $e) {
                Log::error("Failed to initialize plugin '{$dbPlugin->code}': " . $e->getMessage());
            }
        }

        $this->pluginsInitialized = true;
    }

    /**
     * Register scheduled tasks for all enabled plugins.
     * Called from Console Kernel. Only loads main plugin class and config for scheduling.
     * Avoids full HTTP/plugin boot overhead.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     */
    public function registerPluginSchedules(Schedule $schedule): void
    {
        Plugin::where('is_enabled', true)
            ->get()
            ->each(function ($dbPlugin) use ($schedule) {
                try {
                    $pluginInstance = $this->loadPlugin($dbPlugin->code);
                    if (!$pluginInstance) {
                        return;
                    }
                    if (!empty($dbPlugin->config)) {
                        $values = json_decode($dbPlugin->config, true) ?: [];
                        $values = $this->castConfigValuesByType($dbPlugin->code, $values);
                        $pluginInstance->setConfig($values);
                    }
                    $pluginInstance->schedule($schedule);

                } catch (\Exception $e) {
                    Log::error("Failed to register schedule for plugin '{$dbPlugin->code}': " . $e->getMessage());
                }
            });
    }

    /**
     * Get all enabled plugin instances.
     *
     * This method ensures that all enabled plugins are initialized and then returns them.
     * It's the central point for accessing active plugins.
     *
     * @return array<AbstractPlugin>
     */
    public function getEnabledPlugins(): array
    {
        $this->initializeEnabledPlugins();

        $enabledPluginCodes = Plugin::where('is_enabled', true)
            ->pluck('code')
            ->all();

        return array_intersect_key($this->loadedPlugins, array_flip($enabledPluginCodes));
    }

    /**
     * Get enabled plugins by type
     */
    public function getEnabledPluginsByType(string $type): array
    {
        $this->initializeEnabledPlugins();

        $enabledPluginCodes = Plugin::where('is_enabled', true)
            ->byType($type)
            ->pluck('code')
            ->all();

        return array_intersect_key($this->loadedPlugins, array_flip($enabledPluginCodes));
    }

    /**
     * Get enabled payment plugins
     */
    public function getEnabledPaymentPlugins(): array
    {
        return $this->getEnabledPluginsByType('payment');
    }

    /**
     * install default plugins
     */
    public static function installDefaultPlugins(): void
    {
        foreach (Plugin::PROTECTED_PLUGINS as $pluginCode) {
            if (!Plugin::where('code', $pluginCode)->exists()) {
                $pluginManager = app(self::class);
                $pluginManager->install($pluginCode);
                $pluginManager->enable($pluginCode);
                Log::info("Installed and enabled default plugin: {$pluginCode}");
            }
        }
    }

    /**
     * 根据 config.json 的类型信息对配置值进行类型转换（仅处理 type=json 键）。
     */
    protected function castConfigValuesByType(string $pluginCode, array $values): array
    {
        $types = $this->getConfigTypes($pluginCode);
        foreach ($values as $key => $value) {
            $type = $types[$key] ?? null;

            if ($type === 'json') {
                if (is_array($value)) {
                    continue;
                }
                
                if (is_string($value) && $value !== '') {
                    $decoded = json_decode($value, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $values[$key] = $decoded;
                    }
                }
            }
        }
        return $values;
    }

    /**
     * 读取并缓存插件 config.json 中的键类型映射。
     */
    protected function getConfigTypes(string $pluginCode): array
    {
        if (isset($this->configTypesCache[$pluginCode])) {
            return $this->configTypesCache[$pluginCode];
        }
        $types = [];
        $configFile = $this->getPluginPath($pluginCode) . '/config.json';
        if (File::exists($configFile)) {
            $config = json_decode(File::get($configFile), true);
            $fields = $config['config'] ?? [];
            foreach ($fields as $key => $meta) {
                $types[$key] = is_array($meta) ? ($meta['type'] ?? 'string') : 'string';
            }
        }
        $this->configTypesCache[$pluginCode] = $types;
        return $types;
    }
}
