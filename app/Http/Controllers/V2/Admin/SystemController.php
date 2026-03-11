<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Utils\CacheKey;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\Contracts\WorkloadRepository;
use Laravel\Horizon\WaitTimeCalculator;
use App\Helpers\ResponseEnum;

class SystemController extends Controller
{
    public function getSystemStatus()
    {
        $data = [
            'schedule' => $this->getScheduleStatus(),
            'horizon' => $this->getHorizonStatus(),
            'schedule_last_runtime' => Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null)),
            'logs' => $this->getLogStatistics()
        ];
        return $this->success($data);
    }

    /**
     * 获取日志统计信息
     * 
     * @return array 各级别日志的数量统计
     */
    protected function getLogStatistics(): array
    {
        $statistics = [
            'info' => 0,
            'warning' => 0,
            'error' => 0,
            'total' => 0
        ];

        $logs = $this->getFileSystemLogs(1, 2000, null, null)['data'] ?? [];
        foreach ($logs as $log) {
            $level = strtoupper((string) ($log['level'] ?? 'INFO'));
            if ($level === 'ERROR') {
                $statistics['error']++;
            } elseif ($level === 'WARNING') {
                $statistics['warning']++;
            } else {
                $statistics['info']++;
            }
            $statistics['total']++;
        }

        return $statistics;
    }

    public function getQueueWorkload(WorkloadRepository $workload)
    {
        return $this->success(collect($workload->get())->sortBy('name')->values()->toArray());
    }

    protected function getScheduleStatus(): bool
    {
        return (time() - 120) < Cache::get(CacheKey::get('SCHEDULE_LAST_CHECK_AT', null));
    }

    protected function getHorizonStatus(): bool
    {
        if (!$masters = app(MasterSupervisorRepository::class)->all()) {
            return false;
        }

        return collect($masters)->contains(function ($master) {
            return $master->status === 'paused';
        }) ? false : true;
    }

    public function getQueueStats()
    {
        $data = [
            'failedJobs' => app(JobRepository::class)->countRecentlyFailed(),
            'jobsPerMinute' => app(MetricsRepository::class)->jobsProcessedPerMinute(),
            'pausedMasters' => $this->totalPausedMasters(),
            'periods' => [
                'failedJobs' => config('horizon.trim.recent_failed', config('horizon.trim.failed')),
                'recentJobs' => config('horizon.trim.recent'),
            ],
            'processes' => $this->totalProcessCount(),
            'queueWithMaxRuntime' => app(MetricsRepository::class)->queueWithMaximumRuntime(),
            'queueWithMaxThroughput' => app(MetricsRepository::class)->queueWithMaximumThroughput(),
            'recentJobs' => app(JobRepository::class)->countRecent(),
            'status' => $this->getHorizonStatus(),
            'wait' => collect(app(WaitTimeCalculator::class)->calculate())->take(1),
        ];
        return $this->success($data);
    }

    /**
     * Get the total process count across all supervisors.
     *
     * @return int
     */
    protected function totalProcessCount()
    {
        $supervisors = app(SupervisorRepository::class)->all();

        return collect($supervisors)->reduce(function ($carry, $supervisor) {
            return $carry + collect($supervisor->processes)->sum();
        }, 0);
    }

    /**
     * Get the number of master supervisors that are currently paused.
     *
     * @return int
     */
    protected function totalPausedMasters()
    {
        if (!$masters = app(MasterSupervisorRepository::class)->all()) {
            return 0;
        }

        return collect($masters)->filter(function ($master) {
            return $master->status === 'paused';
        })->count();
    }

    public function getSystemLog(Request $request)
    {
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('page_size') >= 10 ? $request->input('page_size') : 10;
        $level = $request->input('level');
        $keyword = $request->input('keyword');

        return response($this->getFileSystemLogs($current, $pageSize, $level, $keyword));
    }

    /**
     * Fallback for environments where DB log channel isn't used or DB inserts fail.
     */
    private function getFileSystemLogs(int $current, int $pageSize, ?string $level, ?string $keyword): array
    {
        $files = $this->getSystemLogFiles();
        if (empty($files)) {
            return [
                'data' => [],
                'total' => 0,
                'source' => 'file'
            ];
        }

        $entries = [];
        foreach ($files as $file) {
            $entries = array_merge($entries, $this->parseLogFile($file, 3000));
            if (count($entries) >= 6000) {
                break;
            }
        }

        usort($entries, fn($a, $b) => ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0));

        $normalizedLevel = $level ? strtoupper($level) : null;
        $keyword = $keyword ? (string) $keyword : null;

        $filtered = array_values(array_filter($entries, function ($row) use ($normalizedLevel, $keyword) {
            if ($normalizedLevel && strtoupper((string) ($row['level'] ?? '')) !== $normalizedLevel) {
                return false;
            }
            if ($keyword) {
                $haystacks = [
                    (string) ($row['title'] ?? ''),
                    (string) ($row['uri'] ?? ''),
                    (string) ($row['context'] ?? ''),
                ];
                foreach ($haystacks as $haystack) {
                    if ($haystack !== '' && stripos($haystack, $keyword) !== false) {
                        return true;
                    }
                }
                return false;
            }
            return true;
        }));

        $total = count($filtered);
        $current = max(1, $current);
        $offset = ($current - 1) * $pageSize;
        $page = array_slice($filtered, $offset, $pageSize);

        return [
            'data' => $page,
            'total' => $total,
            'source' => 'file'
        ];
    }

    /**
     * @return array<int, string> ordered by mtime desc
     */
    private function getSystemLogFiles(): array
    {
        $paths = [];

        $backup = storage_path('logs/backup.log');
        if (File::exists($backup) && File::isFile($backup)) {
            $paths[] = $backup;
        }

        $logsDir = storage_path('logs');
        if (File::exists($logsDir) && File::isDirectory($logsDir)) {
            $candidates = collect(File::files($logsDir))
                ->filter(fn($file) => preg_match('/^laravel(-\\d{4}-\\d{2}-\\d{2})?\\.log$/', $file->getFilename()))
                ->sortByDesc(fn($file) => $file->getMTime())
                ->take(3)
                ->map(fn($file) => $file->getRealPath())
                ->filter()
                ->values()
                ->all();

            $paths = array_merge($paths, $candidates);
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array<int, string> ordered by mtime desc
     */
    private function getAllSystemLogFiles(): array
    {
        $paths = [];

        $backup = storage_path('logs/backup.log');
        if (File::exists($backup) && File::isFile($backup)) {
            $paths[] = $backup;
        }

        $logsDir = storage_path('logs');
        if (File::exists($logsDir) && File::isDirectory($logsDir)) {
            $candidates = collect(File::files($logsDir))
                ->filter(fn($file) => preg_match('/^laravel(-\\d{4}-\\d{2}-\\d{2})?\\.log$/', $file->getFilename()))
                ->sortByDesc(fn($file) => $file->getMTime())
                ->map(fn($file) => $file->getRealPath())
                ->filter()
                ->values()
                ->all();

            $paths = array_merge($paths, $candidates);
        }

        return array_values(array_unique($paths));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseLogFile(string $path, int $maxLines): array
    {
        $lines = $this->tailLines($path, $maxLines);
        if (empty($lines)) {
            return [];
        }

        $entries = [];
        $current = null;
        foreach ($lines as $line) {
            $parsed = $this->parseLogStartLine($line);
            if ($parsed) {
                if ($current) {
                    $entries[] = $this->normalizeFileLogEntry($current);
                }
                $current = $parsed;
                continue;
            }

            if ($current) {
                $current['raw'] = isset($current['raw']) && $current['raw'] !== ''
                    ? ($current['raw'] . "\n" . $line)
                    : $line;
            }
        }

        if ($current) {
            $entries[] = $this->normalizeFileLogEntry($current);
        }

        return $entries;
    }

    /**
     * @return array{created_at:int,level:string,title:string,context_array:array<string,mixed>,uri:?string,method:?string,host:?string,ip:?string,data:?string,raw:?string}|null
     */
    private function parseLogStartLine(string $line): ?array
    {
        if (!preg_match('/^\\[(?<dt>[^\\]]+)\\]\\s+(?<env>[^\\.]+)\\.(?<level>[A-Z]+):\\s+(?<body>.*)$/', $line, $matches)) {
            return null;
        }

        $timestamp = strtotime($matches['dt']);
        if ($timestamp === false) {
            $timestamp = time();
        }

        [$message, $context] = $this->splitMessageAndContext($matches['body']);

        return [
            'created_at' => (int) $timestamp,
            'level' => strtoupper($matches['level']),
            'title' => $message,
            'context_array' => $context ?? [],
            'uri' => null,
            'method' => null,
            'host' => null,
            'ip' => null,
            'data' => null,
            'raw' => null,
        ];
    }

    /**
     * @return array{0:string,1:?array<string,mixed>}
     */
    private function splitMessageAndContext(string $body): array
    {
        $body = rtrim($body);
        $pos = strrpos($body, ' {');
        if ($pos === false) {
            return [$body, null];
        }

        $maybeJson = substr($body, $pos + 1);
        $decoded = json_decode($maybeJson, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            return [$body, null];
        }

        $message = rtrim(substr($body, 0, $pos));
        return [$message, $decoded];
    }

    /**
     * @param array{created_at:int,level:string,title:string,context_array:array<string,mixed>,uri:?string,method:?string,host:?string,ip:?string,data:?string,raw:?string} $entry
     * @return array<string, mixed>
     */
    private function normalizeFileLogEntry(array $entry): array
    {
        $context = $entry['context_array'] ?? [];

        // Normalize exception shape so the admin UI can render it (expects \0*\0traceAsString etc.).
        if (isset($context['exception']) && is_array($context['exception'])) {
            $ex = $context['exception'];
            $context['exception'] = [
                "\0*\0message" => (string) ($ex['message'] ?? ''),
                "\0*\0file" => (string) ($ex['file'] ?? ''),
                "\0*\0line" => (string) ($ex['line'] ?? ''),
                "\0*\0traceAsString" => (string) ($ex['traceAsString'] ?? $ex['trace'] ?? ''),
            ];
        }

        if (!empty($entry['raw'])) {
            $context['raw'] = (string) $entry['raw'];
        }

        // Extract request metadata from our backup logger fallback if present.
        if (isset($context['log']) && is_array($context['log'])) {
            $entry['host'] = $entry['host'] ?? ($context['log']['host'] ?? null);
            $entry['uri'] = $entry['uri'] ?? ($context['log']['uri'] ?? null);
            $entry['method'] = $entry['method'] ?? ($context['log']['method'] ?? null);
        }

        $contextJson = json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return [
            'id' => $entry['created_at'] . '-' . substr(sha1(($entry['title'] ?? '') . ($entry['raw'] ?? '')), 0, 8),
            'title' => $entry['title'] ?? '',
            'level' => strtoupper((string) ($entry['level'] ?? 'INFO')),
            'host' => $entry['host'],
            'uri' => $entry['uri'],
            'method' => $entry['method'],
            'ip' => $entry['ip'],
            'data' => $entry['data'],
            'context' => $contextJson ?: null,
            'created_at' => $entry['created_at'],
            'updated_at' => $entry['created_at'],
        ];
    }

    /**
     * Read last N lines from a file without loading the whole file.
     *
     * @return array<int, string>
     */
    private function tailLines(string $path, int $maxLines, int $maxBytes = 1048576): array
    {
        if (!File::exists($path) || !File::isFile($path)) {
            return [];
        }

        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return [];
        }

        $buffer = '';
        $chunkSize = 4096;

        fseek($handle, 0, SEEK_END);
        $pos = ftell($handle);
        if (!is_int($pos)) {
            fclose($handle);
            return [];
        }

        $lineCount = 0;
        while ($pos > 0 && $lineCount <= $maxLines && strlen($buffer) < $maxBytes) {
            $readSize = min($chunkSize, $pos);
            $pos -= $readSize;
            fseek($handle, $pos);

            $chunk = fread($handle, $readSize);
            if ($chunk === false) {
                break;
            }

            $buffer = $chunk . $buffer;
            $lineCount = substr_count($buffer, "\n");
        }

        fclose($handle);

        $lines = preg_split("/\\r?\\n/", trim($buffer));
        if (!$lines) {
            return [];
        }

        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }

        return array_values(array_filter($lines, fn($line) => $line !== ''));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function parseLogBlocks(string $path): array
    {
        if (!File::exists($path) || !File::isFile($path)) {
            return [];
        }

        $content = File::get($path);
        if ($content === '') {
            return [];
        }

        $lines = preg_split("/\\r?\\n/", rtrim($content, "\r\n"));
        if (!is_array($lines) || empty($lines)) {
            return [];
        }

        $entries = [];
        $current = null;
        $currentLines = [];

        foreach ($lines as $line) {
            $parsed = $this->parseLogStartLine($line);
            if ($parsed) {
                if ($current !== null) {
                    $current['raw_lines'] = $currentLines;
                    $current['raw'] = count($currentLines) > 1 ? implode("\n", array_slice($currentLines, 1)) : null;
                    $entries[] = $current;
                }
                $current = $parsed;
                $currentLines = [$line];
                continue;
            }

            if ($current !== null) {
                $currentLines[] = $line;
            }
        }

        if ($current !== null) {
            $current['raw_lines'] = $currentLines;
            $current['raw'] = count($currentLines) > 1 ? implode("\n", array_slice($currentLines, 1)) : null;
            $entries[] = $current;
        }

        return $entries;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     */
    private function writeLogBlocks(string $path, array $blocks): void
    {
        $content = collect($blocks)
            ->map(fn($block) => implode(PHP_EOL, $block['raw_lines'] ?? []))
            ->filter(fn($block) => $block !== '')
            ->implode(PHP_EOL);

        if ($content !== '') {
            $content .= PHP_EOL;
        }

        File::put($path, $content);
    }

    private function shouldMatchClearCondition(array $entry, int $cutoffTimestamp, string $level): bool
    {
        if ((int) ($entry['created_at'] ?? 0) >= $cutoffTimestamp) {
            return false;
        }

        if ($level === 'all') {
            return true;
        }

        return strtoupper((string) ($entry['level'] ?? 'INFO')) === strtoupper($level);
    }

    public function getAuditLog(Request $request)
    {
        $current = max(1, (int) $request->input('current', 1));
        $pageSize = max(10, (int) $request->input('page_size', 10));

        $builder = AdminAuditLog::with('admin:id,email,is_admin,is_staff')
            ->orderBy('id', 'DESC')
            ->when($request->input('action'), fn($query, $value) => $query->where('action', $value))
            ->when($request->input('admin_id'), fn($query, $value) => $query->where('admin_id', $value))
            ->when($request->input('keyword'), function ($query, $keyword) {
                $query->where(function ($query) use ($keyword) {
                    $query->where('uri', 'like', '%' . $keyword . '%')
                        ->orWhere('request_data', 'like', '%' . $keyword . '%');
                });
            });

        $total = $builder->count();
        $res = $builder->forPage($current, $pageSize)->get();

        return response([
            'data' => $res,
            'total' => $total,
        ]);
    }

    public function getHorizonFailedJobs(Request $request, JobRepository $jobRepository)
    {
        $current = max(1, (int) $request->input('current', 1));
        $pageSize = max(10, (int) $request->input('page_size', 20));
        $offset = ($current - 1) * $pageSize;

        $failedJobs = collect($jobRepository->getFailed())
            ->sortByDesc('failed_at')
            ->slice($offset, $pageSize)
            ->values();

        $total = $jobRepository->countFailed();

        return response()->json([
            'data' => $failedJobs,
            'total' => $total,
            'current' => $current,
            'page_size' => $pageSize,
        ]);
    }

    /**
     * 清除系统日志
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function clearSystemLog(Request $request)
    {
        $request->validate([
            'days' => 'integer|min:0|max:365',
            'level' => 'string|in:info,warning,error,all',
            'limit' => 'integer|min:100|max:10000'
        ], [
            'days.required' => '请指定要清除多少天前的日志',
            'days.integer' => '天数必须为整数',
            'days.min' => '天数不能少于1天',
            'days.max' => '天数不能超过365天',
            'level.in' => '日志级别只能是：info、warning、error、all',
            'limit.min' => '单次清除数量不能少于100条',
            'limit.max' => '单次清除数量不能超过10000条'
        ]);

        $days = $request->input('days', 30); // 默认清除30天前的日志
        $level = $request->input('level', 'all'); // 默认清除所有级别
        $limit = $request->input('limit', 1000); // 默认单次清除1000条

        try {
            $cutoffDate = now()->subDays($days);
            $cutoffTimestamp = $cutoffDate->timestamp;
            $totalCount = 0;
            $deletedCount = 0;
            $files = array_reverse($this->getAllSystemLogFiles());

            foreach ($files as $file) {
                $blocks = $this->parseLogBlocks($file);
                if (empty($blocks)) {
                    continue;
                }

                $changed = false;
                $keptBlocks = [];

                foreach ($blocks as $block) {
                    if ($this->shouldMatchClearCondition($block, $cutoffTimestamp, $level)) {
                        $totalCount++;
                        if ($deletedCount < $limit) {
                            $deletedCount++;
                            $changed = true;
                            continue;
                        }
                    }

                    $keptBlocks[] = $block;
                }

                if ($changed) {
                    $this->writeLogBlocks($file, $keptBlocks);
                }
            }

            if ($totalCount === 0) {
                return $this->success([
                    'message' => '没有找到符合条件的日志记录',
                    'deleted_count' => 0,
                    'total_count' => 0,
                    'remaining_count' => 0,
                    'source' => 'file',
                ]);
            }

            return $this->success([
                'message' => '日志清除完成',
                'deleted_count' => $deletedCount,
                'total_count' => $totalCount,
                'remaining_count' => max(0, $totalCount - $deletedCount),
                'source' => 'file',
            ]);

        } catch (\Exception $e) {
            return $this->fail(ResponseEnum::HTTP_ERROR, null, '清除日志失败：' . $e->getMessage());
        }
    }

    /**
     * 获取日志清除统计信息
     * 
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLogClearStats(Request $request)
    {
        $days = $request->input('days', 30);
        $level = $request->input('level', 'all');

        try {
            $cutoffDate = now()->subDays($days);
            $cutoffTimestamp = $cutoffDate->timestamp;
            $files = $this->getAllSystemLogFiles();
            $totalLogs = 0;
            $logsToClear = 0;
            $oldestLog = null;
            $newestLog = null;

            foreach ($files as $file) {
                foreach ($this->parseLogBlocks($file) as $block) {
                    $totalLogs++;

                    if ($this->shouldMatchClearCondition($block, $cutoffTimestamp, $level)) {
                        $logsToClear++;
                    }

                    if ($oldestLog === null || (int) $block['created_at'] < (int) $oldestLog['created_at']) {
                        $oldestLog = $block;
                    }
                    if ($newestLog === null || (int) $block['created_at'] > (int) $newestLog['created_at']) {
                        $newestLog = $block;
                    }
                }
            }

            $stats = [
                'days' => $days,
                'level' => $level,
                'cutoff_date' => $cutoffDate->format(format: 'Y-m-d H:i:s'),
                'total_logs' => $totalLogs,
                'logs_to_clear' => $logsToClear,
                'oldest_log' => $oldestLog ? $this->normalizeFileLogEntry($oldestLog) : null,
                'newest_log' => $newestLog ? $this->normalizeFileLogEntry($newestLog) : null,
                'source' => 'file',
            ];

            return $this->success($stats);

        } catch (\Exception $e) {
            return $this->fail(ResponseEnum::HTTP_ERROR, null, '获取统计信息失败：' . $e->getMessage());
        }
    }
}
