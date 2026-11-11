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

    private function files(int $exit = 0, bool $large = false): array
    {
        app()->instance(MachineInstallScript::class, new class($exit, $large) extends MachineInstallScript {
            public function __construct(private int $exit, private bool $large) {}
            public function render(string $url, ServerMachine $machine, string $agent): string
            {
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

    private function runBootstrap(int $callbackExit = 0): Process
    {
        // Only the generated bootstrap runs: installer and curl are inert local fixtures.
        $shell = "curl() { printf 'reported\\n' >> ./callback-count; return " . $callbackExit . "; }; export -f curl; exec bash ./private/bootstrap.sh";
        $process = new Process([$this->bash, '-c', $shell], $this->directory, null, null, 15);
        $process->run(); return $process;
    }

    private function receiptData(): array
    {
        return json_decode(file_get_contents($this->directory . '/private/result.json'), true, 8, JSON_THROW_ON_ERROR);
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
