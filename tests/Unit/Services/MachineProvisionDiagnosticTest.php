<?php
namespace Tests\Unit\Services;

use App\Services\ServerMachine\Cloud\CloudException;
use App\Services\ServerMachine\Cloud\MachineProvisionDiagnostic as Diagnostic;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MachineProvisionDiagnosticTest extends TestCase
{
    public function test_public_location_and_fixed_exception_fields_omit_messages_paths_and_stacks(): void
    {
        $error = new \ErrorException('private-sentinel-never-export', 0, E_WARNING,
            base_path('app/Services/ServerMachine/Cloud/MachineProvisionService.php'), 23);
        $safe = Diagnostic::project($error, 'readback');
        $this->assertSame('ErrorException', $safe['error_class']);
        $this->assertSame('readback', $safe['phase']);
        $this->assertSame('app/Services/ServerMachine/Cloud/MachineProvisionService.php', $safe['file']);
        $this->assertSame(23, $safe['line']); $this->assertSame('warning', $safe['severity']);
        $this->assertSame($safe, Diagnostic::safe($safe));
        foreach (['private-sentinel-never-export', base_path(), 'message', 'trace', 'arguments'] as $secret)
            $this->assertStringNotContainsString($secret, json_encode($safe));
    }

    public static function privateLocations(): array
    {
        return [['/root/private.php'], ['C:\\private\\MachineProvisionService.php'], [__FILE__],
            [dirname(__DIR__, 3) . '/app/Services/ServerMachine/Cloud/../Cloud/MachineProvisionService.php']];
    }
    #[DataProvider('privateLocations')]
    public function test_unknown_noncanonical_and_private_paths_are_hidden(string $path): void
    {
        $safe = Diagnostic::project(new \ErrorException('private-sentinel-never-export', 0, E_WARNING, $path, 12), 'admission');
        $this->assertFalse($safe['source_known']); $this->assertNull($safe['file']); $this->assertNull($safe['line']);
    }

    public function test_only_known_domain_codes_and_exception_classes_can_escape(): void
    {
        $safe = Diagnostic::project(new CloudException('isolated_manual_cli_required'), 'admission');
        $this->assertSame(CloudException::class, $safe['error_class']);
        $this->assertSame('isolated_manual_cli_required', $safe['cause']);
        $safe = Diagnostic::project(new class('private-sentinel-never-export') extends \RuntimeException {}, 'admission');
        $this->assertSame('other', $safe['error_class']); $this->assertSame('other', $safe['cause']);
        $this->assertStringNotContainsString('private-sentinel-never-export', json_encode($safe));
    }

    public function test_malformed_extra_or_secret_fields_never_pass_the_export_projection(): void
    {
        $safe = Diagnostic::project(new \ErrorException('private-sentinel-never-export', 0, E_WARNING,
            base_path('app/Services/ServerMachine/Cloud/MachineProvisionService.php'), 23), 'readback');
        foreach ([['schema' => true], ['phase' => 'private-sentinel-never-export'], ['error_class' => 'private-sentinel-never-export'],
            ['file' => '/root/private-sentinel-never-export'], ['line' => true], ['line' => -1], ['line' => 1000001],
            ['cause' => 'private-sentinel-never-export'], ['message' => 'private-sentinel-never-export'], ['source_known' => 1],
            ['source_known' => false], ['severity' => null], ['trace' => []]] as $change) {
            $this->assertNull(Diagnostic::safe(array_replace($safe, $change)));
        }
    }
}
