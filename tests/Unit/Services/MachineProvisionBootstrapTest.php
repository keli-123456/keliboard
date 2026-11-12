<?php
namespace Tests\Unit\Services;

use App\Models\ServerMachine;
use App\Services\ServerMachine\Cloud\ProvisionBootstrap;
use App\Services\ServerMachine\MachineInstallScript;
use App\Support\Setting;
use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class MachineProvisionBootstrapTest extends TestCase
{
    private string $directory;
    private string $bash;
    private string $identity = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bash = PHP_OS_FAMILY === 'Windows' ? 'C:/Program Files/Git/bin/bash.exe' : '/bin/bash';
        $this->assertFileExists($this->bash);
        $this->directory = sys_get_temp_dir() . '/keli-provision-test-' . bin2hex(random_bytes(12));
        mkdir($this->directory, 0700); mkdir($this->directory . '/private', 0700);
        app()->instance(Setting::class, new class { public function get(string $key, mixed $default = null): mixed { return $default; } });
    }

    protected function tearDown(): void
    {
        $root = realpath($this->directory);
        if ($root && dirname($root) === realpath(sys_get_temp_dir()) && str_starts_with(basename($root), 'keli-provision-test-')) {
            (new Filesystem())->deleteDirectory($root);
        }
        parent::tearDown();
    }

    private function files(int $exit = 0, bool $large = false, ?string $script = null): array
    {
        app()->instance(MachineInstallScript::class, new class($exit, $large, $script) extends MachineInstallScript {
            public function __construct(private int $exit, private bool $large, private ?string $script) {}
            public function render(string $url, ServerMachine $machine, string $agent): string
            {
                if ($this->script !== null) return $this->script;
                return "#!/usr/bin/env bash\n" . ($this->large ? str_repeat("# Preserved synthetic installer comment\n", 800) : '')
                    . "printf 'installed\\n' >> ./install-count\nexit " . $this->exit . "\n";
            }
        });
        $machine = new ServerMachine(); $machine->forceFill(['id' => 1, 'token' => 'synthetic-only', 'name' => 'Synthetic']);
        $task = '10000000-0000-4000-8000-000000000001';
        $files = Yaml::parse((new ProvisionBootstrap())->build($machine, 'https://panel.example.test', $task, $this->identity, str_repeat('c', 64)))['write_files'];
        foreach ($files as $file) {
            $plain = ($file['encoding'] ?? '') === 'gz+b64' ? gzdecode(base64_decode($file['content'], true)) : $file['content'];
            $this->assertIsString($plain);
            $content = str_replace(['/var/lib/kelinode-provision/' . $task, '/etc/kelinode'], ['./private', './node'], $plain);
            $this->assertStringNotContainsString('/etc/kelinode', $content);
            $this->assertStringNotContainsString('/var/lib/kelinode-provision', $content);
            file_put_contents($this->directory . '/private/' . basename($file['path']), $content);
        }
        return $files;
    }

    private function runBootstrap(int $callbackExit = 0, string $prefix = ''): Process
    {
        // Only the generated bootstrap runs: installer and curl are inert local fixtures.
        $shell = $prefix . "curl() { printf 'reported\\n' >> ./callback-count; return " . $callbackExit . "; }; export -f curl; exec bash ./private/bootstrap.sh";
        $process = new Process([$this->bash, '-c', $shell], $this->directory, null, null, 15);
        $process->run(); return $process;
    }

    private function receiptData(): array
    {
        return json_decode(file_get_contents($this->directory . '/private/result.json'), true, 8, JSON_THROW_ON_ERROR);
    }

    private function diagnostics(string $script): array
    {
        return json_decode(file_get_contents($this->directory . '/private/diagnostic-' . $script . '.json'), true, 8, JSON_THROW_ON_ERROR);
    }

    public function test_explicit_installer_exit_records_phase_code_and_exit_line_without_command_text(): void
    {
        $this->files(script: "#!/bin/bash\nset -Eeuo pipefail\nkeli_provision_stage installer_download\nprintf 'synthetic-secret-not-for-export\\n'\nkeli_provision_exit 23 \"\$LINENO\"; exit 23\n");
        $process = $this->runBootstrap();
        $this->assertSame(23, $process->getExitCode(), $process->getErrorOutput());
        $step = $this->diagnostics('installer');
        $this->assertSame('installer_download', $step['phase']);
        $this->assertSame('exit', $step['event']);
        $this->assertSame(23, $step['exit_code']);
        $this->assertSame(5, $step['exit_line']);
        $this->assertNull($step['error_line']);
        $this->assertStringNotContainsString('synthetic-secret', json_encode($step));
        $this->assertSame(23, $this->diagnostics('bootstrap')['exit_code']);
        $this->assertSame(['task_id', 'installation_id', 'state'], array_keys($this->receiptData()));
    }

    public static function shellErrors(): array
    {
        return ['simple command' => ['false', 1], 'pipeline' => ["printf fixture | (exit 37)", 37],
            'subshell' => ['(exit 39)', 39], 'function' => ['fail_step() { return 41; }; fail_step', 41]];
    }

    #[DataProvider('shellErrors')]
    public function test_err_trap_records_bash_call_site_and_preserves_exit(string $command, int $code): void
    {
        $this->files(script: "#!/bin/bash\nset -Eeuo pipefail\nkeli_provision_stage dependencies\n" . $command . "\n");
        $process = $this->runBootstrap();
        $this->assertSame($code, $process->getExitCode(), $process->getErrorOutput());
        $step = $this->diagnostics('installer');
        $this->assertSame('dependencies', $step['phase']);
        $this->assertSame($code, $step['exit_code']);
        $this->assertSame($code, $step['error_code']);
        $this->assertSame(4, $step['error_line']);
        $this->assertSame('failed', $this->receiptData()['state']);
    }

    public function test_diagnostic_storage_failure_cannot_change_exit_or_callback(): void
    {
        $this->files(23);
        $process = $this->runBootstrap(22, 'mv() { return 77; }; export -f mv; ');
        $this->assertSame(23, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('failed', $this->receiptData()['state']);
        $this->assertSame("reported\n", file_get_contents($this->directory . '/callback-count'));
        $this->assertSame([], glob($this->directory . '/private/.diagnostic-*'));
    }

    public function test_timeout_status_is_not_replaced_by_diagnostic_or_callback_status(): void
    {
        $this->files();
        $process = $this->runBootstrap(22, 'timeout() { return 124; }; export -f timeout; ');
        $this->assertSame(124, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame(124, $this->diagnostics('bootstrap')['exit_code']);
        $this->assertSame('installer', $this->diagnostics('bootstrap')['phase']);
        $this->assertSame('failed', $this->receiptData()['state']);
        $this->assertFileDoesNotExist($this->directory . '/private/diagnostic-installer.json');
    }

    public function test_unknown_phase_and_unrelated_bash_child_do_not_export_arbitrary_data(): void
    {
        $this->files(script: "#!/bin/bash\nset -Eeuo pipefail\nkeli_provision_stage dependencies\nkeli_provision_stage 'synthetic-secret-argument'\nbash -c 'false; true'\nexit 0\n");
        $process = $this->runBootstrap(22);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('dependencies', $this->diagnostics('installer')['phase']);
        $this->assertSame(0, $this->diagnostics('installer')['exit_code']);
        $this->assertStringNotContainsString('synthetic-secret', file_get_contents($this->directory . '/private/diagnostic-installer.json'));
    }

    public function test_plain_explicit_exit_without_a_line_hook_stays_unknown_instead_of_bash_exit_line_one(): void
    {
        $this->files(23);
        $this->assertSame(23, $this->runBootstrap()->getExitCode());
        $this->assertSame(23, $this->diagnostics('installer')['exit_code']);
        $this->assertNull($this->diagnostics('installer')['exit_line']);
        $this->assertNull($this->diagnostics('installer')['error_line']);
    }

    public static function nativeContexts(): array
    {
        return ['task diagnostics' => [true], 'standalone without hooks' => [false]];
    }

    #[DataProvider('nativeContexts')]
    public function test_native_installer_cleanup_trap_preserves_explicit_exit_code_and_line(bool $withDiagnostics): void
    {
        $this->files(script: "#!/bin/bash\nset -Eeuo pipefail\nbash ./native/keli-native-node-install.sh --machine-url https://synthetic.invalid --machine-id 1 --machine-token synthetic\n");
        $bootstrap = $this->directory . '/private/bootstrap.sh';
        file_put_contents($bootstrap, str_replace('/tmp/keli-native-node-install.sh', './native/keli-native-node-install.sh', file_get_contents($bootstrap)));
        mkdir($this->directory . '/native', 0700);
        mkdir($this->directory . '/native-work', 0700);
        $this->assertSame(realpath($this->directory), dirname(realpath($this->directory . '/native-work')));
        $native = str_replace("\r\n", "\n", file_get_contents(resource_path('server-machine/kelinode-rs-install.sh')));
        $mock = <<<'SH'
LOCK_DIR=./native-lock
require_root() { :; }
install_base_packages() { :; }
keli_install_optional_ping() { :; }
resolve_version() { printf '%s' v0.0.1; }
install_native_node() { keli_install_stage archive_verify; keli_install_exit 53 "$LINENO"; }
SH;
        $this->assertSame(1, substr_count($native, "\nmain \"\$@\"\n"));
        $native = str_replace("\nmain \"\$@\"\n", "\n" . $mock . "\nmain \"\$@\"\n", $native);
        $native = str_replace('WORK_DIR="$(mktemp -d)"', 'WORK_DIR=./native-work', $native);
        $expectedLine = substr_count(substr($native, 0, strpos($native, 'keli_install_exit 53')), "\n") + 1;
        file_put_contents($this->directory . '/native/keli-native-node-install.sh', $native);
        if (!$withDiagnostics) {
            $process = new Process([$this->bash, './native/keli-native-node-install.sh', '--machine-url', 'https://synthetic.invalid',
                '--machine-id', '1', '--machine-token', 'synthetic'], $this->directory, ['BASH_ENV' => false], null, 15);
            $process->run();
            $this->assertSame(53, $process->getExitCode(), $process->getErrorOutput());
            $this->assertDirectoryDoesNotExist($this->directory . '/native-lock');
            $this->assertDirectoryDoesNotExist($this->directory . '/native-work');
            $this->assertFileDoesNotExist($this->directory . '/private/diagnostic-native_installer.json');
            $this->assertFileDoesNotExist($this->directory . '/callback-count');
            return;
        }
        $process = $this->runBootstrap(22);
        $this->assertSame(53, $process->getExitCode(), $process->getErrorOutput());
        $step = $this->diagnostics('native_installer');
        $this->assertSame('archive_verify', $step['phase']);
        $this->assertSame('exit', $step['event']);
        $this->assertSame(53, $step['exit_code']);
        $this->assertSame($expectedLine, $step['exit_line']);
        $this->assertDirectoryDoesNotExist($this->directory . '/native-lock');
        $this->assertDirectoryDoesNotExist($this->directory . '/native-work');
        $this->assertSame('failed', $this->receiptData()['state']);
        $python = new Process([PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3', '-c',
            "import json,sys\nsys.path.insert(0,sys.argv[1])\nimport bootstrap_diagnostics as p\nr=json.load(sys.stdin)\nprint(json.dumps(p.execution_summary({'state':'observed'},json.dumps(r).encode(),r['task_id'],r['installation_id'],'native_installer')))",
            base_path('tools/machine-acceptance')]);
        $python->setInput(json_encode($step, JSON_THROW_ON_ERROR)); $python->run();
        $this->assertSame(0, $python->getExitCode(), $python->getErrorOutput());
        $safe = json_decode($python->getOutput(), true, 8, JSON_THROW_ON_ERROR);
        $this->assertSame('observed', $safe['state']);
        $this->assertSame(53, $safe['exit_code']);
        $this->assertSame($expectedLine, $safe['exit_line']);
    }

    public function test_diagnostic_template_has_no_command_capture_or_external_debug_trap(): void
    {
        $script = file_get_contents(resource_path('server-machine/provision-diagnostics.sh'));
        foreach (['BASH_COMMAND', 'set -x', ' DEBUG', 'eval ', 'machine_token', 'MACHINE_TOKEN'] as $text) {
            $this->assertStringNotContainsString($text, $script);
        }
    }

    public static function linuxLifecycleCases(): array
    {
        return ['timeout' => ['timeout', 124], 'installer SIGTERM' => ['installer_term', 143],
            'bootstrap SIGTERM' => ['bootstrap_term', 143]];
    }

    #[DataProvider('linuxLifecycleCases')]
    public function test_real_linux_timeout_and_signals_preserve_callback_and_native_cleanup(string $scenario, int $expected): void
    {
        if (PHP_OS_FAMILY !== 'Linux') $this->markTestSkipped('Real Linux process groups and signals required');
        $this->files(script: "#!/bin/bash\nset -Eeuo pipefail\nbash ./native/keli-native-node-install.sh\n");
        $path = $this->directory . '/private/bootstrap.sh';
        $bootstrap = str_replace('/tmp/keli-native-node-install.sh', './native/keli-native-node-install.sh', file_get_contents($path));
        $needle = 'timeout --signal=TERM --kill-after=15s 600s';
        $this->assertSame(1, substr_count($bootstrap, $needle));
        file_put_contents($path, str_replace($needle, '/usr/bin/timeout --signal=TERM --kill-after=1s ' . ($scenario === 'timeout' ? '2s' : '10s'), $bootstrap));
        mkdir($this->directory . '/native', 0700);
        $source = str_replace("\r\n", "\n", file_get_contents(resource_path('server-machine/kelinode-rs-install.sh')));
        $this->assertSame(1, preg_match('/^native_installer_exit\(\) \{\n.*?^\}/ms', $source, $match));
        $this->assertSame(1, preg_match('/^trap .* TERM$/m', $source, $term));
        $native = "#!/bin/bash\nset -Eeuo pipefail\nLOCK_DIR=./native-lock\nWORK_DIR=./native-work\n" . $match[0]
            . "\nmkdir \"\$LOCK_DIR\" \"\$WORK_DIR\"\ntrap native_installer_exit EXIT\n" . $term[0]
            . "\nkeli_provision_stage base_dependencies\nprintf '%s' \"\$BASHPID\" > ./native-ready.pid\nwhile :; do sleep 0.1; done\n";
        file_put_contents($this->directory . '/native/keli-native-node-install.sh', $native);
        file_put_contents($this->directory . '/fixture-scope.json', json_encode(['schema' => 1, 'synthetic' => true,
            'directory' => realpath($this->directory)], JSON_THROW_ON_ERROR));
        file_put_contents($this->directory . '/run-fixture.sh', "#!/bin/bash\ncurl() { printf 'reported\\n' >> ./callback-count; return 22; }; export -f curl\nexec bash ./private/bootstrap.sh\n");
        foreach (glob($this->directory . '/private/*') as $file) chmod($file, 0600);
        foreach (['fixture-scope.json', 'run-fixture.sh'] as $file) chmod($this->directory . '/' . $file, 0600);
        $process = new Process(['python3', base_path('tools/machine-acceptance/linux_bootstrap_lifecycle.py'),
            '--work', $this->directory, '--scenario', $scenario], $this->directory, null, null, 35);
        $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $result = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
        $evidence = getenv('KELI_LINUX_CI_EVIDENCE_DIR');
        if ($evidence !== false && $evidence !== '') {
            $this->assertSame(realpath(base_path('artifacts')), realpath($evidence));
            $result['callback_state'] = is_file($this->directory . '/private/result.json') ? $this->receiptData()['state'] : null;
            $result['callback_count'] = is_file($this->directory . '/callback-count') ? substr_count(file_get_contents($this->directory . '/callback-count'), "reported\n") : 0;
            foreach (['bootstrap', 'native_installer'] as $script) {
                $result[$script . '_exit_code'] = is_file($this->directory . '/private/diagnostic-' . $script . '.json') ? $this->diagnostics($script)['exit_code'] : null;
            }
            file_put_contents($evidence . '/lifecycle-' . $scenario . '.json', json_encode($result, JSON_THROW_ON_ERROR));
        }
        $this->assertSame($expected, $result['exit_code']);
        $this->assertSame(0, $result['remaining_owned_processes']);
        $this->assertTrue($result['native_cleanup_before_harness']);
        $this->assertSame('failed', $this->receiptData()['state']);
        $this->assertSame("reported\n", file_get_contents($this->directory . '/callback-count'));
        $this->assertSame($expected, $this->diagnostics('bootstrap')['exit_code']);
        $this->assertSame(143, $this->diagnostics('native_installer')['exit_code']);
        foreach (glob($this->directory . '/private/diagnostic-*.json') as $file) {
            $this->assertSame(0600, fileperms($file) & 0777);
            $this->assertSame(posix_geteuid(), fileowner($file));
        }
    }

    public function test_shell_parses_and_success_is_idempotent_with_repeat_receipts(): void
    {
        $files = $this->files();
        $syntax = new Process([$this->bash, '-n', './private/bootstrap.sh'], $this->directory); $syntax->run();
        $this->assertSame(0, $syntax->getExitCode(), $syntax->getErrorOutput());
        $first = $this->runBootstrap(); $this->assertSame(0, $first->getExitCode(), $first->getErrorOutput());
        $this->assertSame($this->identity, file_get_contents($this->directory . '/node/report-instance-id'));
        $this->assertSame('succeeded', $this->receiptData()['state']);
        $this->assertFileExists($this->directory . '/private/succeeded');
        $again = $this->runBootstrap(); $this->assertSame(0, $again->getExitCode());
        $this->assertSame("installed\n", file_get_contents($this->directory . '/install-count'));
        $this->assertSame("reported\nreported\n", file_get_contents($this->directory . '/callback-count'));
        $this->assertStringNotContainsString(str_repeat('c', 64), $files[1]['content']);
    }

    public static function exitCases(): array { return ['installer failure' => [23, 0], 'installer and callback fail' => [23, 22], 'callback alone fails' => [0, 22]]; }
    #[DataProvider('exitCases')]
    public function test_callback_transport_never_masks_installer_exit_status(int $installerExit, int $callbackExit): void
    {
        $this->files($installerExit); $process = $this->runBootstrap($callbackExit);
        $this->assertSame($installerExit, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame($installerExit ? 'failed' : 'succeeded', $this->receiptData()['state']);
        $this->assertSame($installerExit === 0, is_file($this->directory . '/private/succeeded'));
        $this->assertSame("reported\n", file_get_contents($this->directory . '/callback-count'));
    }

    public function test_an_existing_different_identity_is_never_overwritten_or_installed_over(): void
    {
        $this->files(); mkdir($this->directory . '/node', 0700);
        file_put_contents($this->directory . '/node/report-instance-id', str_repeat('b', 32));
        file_put_contents($this->directory . '/private/succeeded', '');
        $process = $this->runBootstrap(); $this->assertSame(83, $process->getExitCode());
        $this->assertSame(str_repeat('b', 32), file_get_contents($this->directory . '/node/report-instance-id'));
        $this->assertFileDoesNotExist($this->directory . '/install-count');
        $this->assertSame('failed', $this->receiptData()['state']);
    }

    #[DataProvider('exitCases')]
    public function test_compressed_installer_restores_exact_bytes_and_preserves_shell_and_callback_status(int $installerExit, int $callbackExit): void
    {
        $files = $this->files($installerExit, true);
        $this->assertSame('gz+b64', $files[0]['encoding']);
        $this->assertSame('gz+b64', $files[1]['encoding']);
        $restore = new Process([PHP_OS_FAMILY === 'Windows' ? 'python' : 'python3', '-c',
            "import base64,gzip,json,sys\nf=json.load(sys.stdin)\nfor item in f:\n if item.get('encoding')=='gz+b64': item['content']=gzip.decompress(base64.b64decode(item['content'],validate=True)).decode('utf-8')\nprint(json.dumps(f))"]);
        $restore->setInput(json_encode($files, JSON_THROW_ON_ERROR)); $restore->run();
        $this->assertSame(0, $restore->getExitCode(), $restore->getErrorOutput());
        $decoded = json_decode($restore->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        $expected = "#!/usr/bin/env bash\n" . str_repeat("# Preserved synthetic installer comment\n", 800)
            . "printf 'installed\\n' >> ./install-count\nexit " . $installerExit . "\n";
        $this->assertSame($expected, $decoded[0]['content']);
        $syntax = new Process([$this->bash, '-n', './private/bootstrap.sh'], $this->directory); $syntax->run();
        $this->assertSame(0, $syntax->getExitCode(), $syntax->getErrorOutput());
        $process = $this->runBootstrap($callbackExit);
        $this->assertSame($installerExit, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame($installerExit ? 'failed' : 'succeeded', $this->receiptData()['state']);
        $this->assertSame("installed\n", file_get_contents($this->directory . '/install-count'));
        $this->assertSame($this->identity, file_get_contents($this->directory . '/node/report-instance-id'));
    }

    public function test_native_shared_installer_is_syntax_checked_without_executing_it(): void
    {
        $machine = new ServerMachine(); $machine->forceFill(['id' => 1, 'token' => 'synthetic-only', 'name' => 'Synthetic']);
        $script = (new MachineInstallScript())->render('https://panel.example.test', $machine, 'kelinode-rs');
        file_put_contents($this->directory . '/native-install.sh', str_replace("\r\n", "\n", $script));
        $process = new Process([$this->bash, '-n', './native-install.sh'], $this->directory); $process->run();
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertFileDoesNotExist($this->directory . '/node');
    }
}
