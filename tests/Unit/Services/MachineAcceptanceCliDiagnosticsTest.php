<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

require_once dirname(__DIR__, 3) . '/tools/machine-acceptance/source_fixture.php';

class MachineAcceptanceCliDiagnosticsTest extends TestCase
{
    public function test_warning_location_is_a_known_public_relative_file_without_message_or_stack(): void
    {
        $error = new \ErrorException('private-sentinel-never-export', 0, E_WARNING,
            base_path('tools/machine-acceptance/source_fixture.php'), 18);
        $result = \keli_acceptance_cli_failure('adopt-source', $error);
        $this->assertSame(['schema' => 1, 'category' => 'php_warning', 'error_class' => 'ErrorException',
            'source_known' => true, 'file' => 'source_fixture.php', 'line' => 18, 'severity' => 'warning'], $result['cli_diagnostic']);
        $this->assertSame('cli_exception', $result['reason']);
        $this->assertStringNotContainsString('private-sentinel-never-export', json_encode($result));
        $this->assertStringNotContainsString(base_path(), json_encode($result));
        $this->assertArrayNotHasKey('trace', $result);
        $this->assertArrayNotHasKey('message', $result);
    }

    public static function hiddenLocations(): array
    {
        return [['/root/private-sentinel-never-export'], ['C:\\private\\source_fixture.php'],
            [__FILE__], [dirname(__DIR__, 3) . '/tools/machine-acceptance/../machine-acceptance/source_fixture.php']];
    }

    #[DataProvider('hiddenLocations')]
    public function test_unknown_private_or_noncanonical_locations_are_redacted(string $file): void
    {
        $result = \keli_acceptance_cli_diagnostic(new \ErrorException('private-sentinel-never-export', 0, E_WARNING, $file, 999));
        $this->assertFalse($result['source_known']);
        $this->assertNull($result['file']);
        $this->assertNull($result['line']);
        $this->assertStringNotContainsString('private-sentinel-never-export', json_encode($result));
    }

    public function test_unknown_exception_class_and_command_are_not_exported_as_identifiers(): void
    {
        $error = new class('private-sentinel-never-export') extends \RuntimeException {};
        $result = \keli_acceptance_cli_failure('private-sentinel-never-export', $error);
        $this->assertSame('other', $result['error_class']);
        $this->assertSame('other', $result['command']);
        $this->assertStringNotContainsString('private-sentinel-never-export', json_encode($result));
    }

    public function test_storage_failure_and_repeated_guard_keep_the_original_throwable_and_first_error(): void
    {
        $error = new \ErrorException('private-sentinel-never-export', 0, E_WARNING,
            base_path('tools/machine-acceptance/source_fixture.php'), 18);
        $calls = 0;
        $run = static fn () => throw $error;
        $save = static function () use (&$calls): void { $calls++; throw new \RuntimeException('private-storage-sentinel'); };
        for ($i = 0; $i < 2; $i++) {
            try { \keli_acceptance_cli_guard($run, $save); $this->fail('Expected original throwable'); }
            catch (\Throwable $caught) { $this->assertSame($error, $caught); }
        }
        $this->assertSame(1, $calls);
        $result = \keli_acceptance_cli_failure('adopt-source', $error);
        $this->assertFalse($result['cli_diagnostic_recorded']);
        $this->assertSame(18, $result['cli_diagnostic']['line']);
    }

    public function test_success_does_not_write_a_diagnostic_and_known_domain_failure_keeps_its_code(): void
    {
        $calls = 0;
        $result = \keli_acceptance_cli_guard(static fn () => ['synthetic' => true], static function () use (&$calls) { $calls++; });
        $this->assertSame(['synthetic' => true], $result);
        $this->assertSame(0, $calls);
        $error = new \RuntimeException('source_fixture_ownership_invalid');
        $stored = null;
        try {
            \keli_acceptance_cli_guard(static fn () => throw $error, static function ($safe) use (&$stored) { $stored = $safe; });
        } catch (\Throwable $caught) { $this->assertSame($error, $caught); }
        $result = \keli_acceptance_cli_failure('adopt-source', $error);
        $this->assertTrue($result['cli_diagnostic_recorded']);
        $this->assertSame($stored, $result['cli_diagnostic']);
        $this->assertSame('source_fixture_ownership_invalid', $result['reason']);
    }

    public function test_field_exception_extra_content_cannot_escape_the_php_projection(): void
    {
        $fields = ['schema' => 1, 'ok' => false, 'reason' => 'source_fixture_evidence_invalid',
            'failed_fields' => ['runtime_nodes'], 'checks' => [], 'raw_log' => 'private-sentinel-never-export'];
        $error = new \KeliSourceFixtureEvidenceRejected($fields);
        $result = \keli_acceptance_cli_failure('adopt-source', $error);
        $this->assertSame('cli_exception', $result['reason']);
        $this->assertArrayNotHasKey('source_fixture_evidence', $result);
        $this->assertStringNotContainsString('private-sentinel-never-export', json_encode($result));
    }

    public function test_every_canonical_field_survives_the_safe_cli_projection(): void
    {
        $checker = require base_path('tools/machine-acceptance/source_fixture_evidence.php');
        $scope = ['run_id' => 'keli-accept-20990101-offline', 'machine_id' => 17, 'account_id' => 1,
            'node_id' => 1, 'ip' => '45.79.10.20', 'version' => 'v0.1.999',
            'binary_sha256' => str_repeat('b', 64), 'health_or_forwarding_claimed' => false];
        $count = 0;
        foreach ([$checker([], [], [], [], [], 2000000000), $checker([], [], [], [], $scope, 2000000000)] as $template) {
            foreach (array_keys($template['checks']) as $field) {
                $fields = $template;
                $fields['checks'] = array_fill_keys(array_keys($template['checks']), true);
                $fields['checks'][$field] = false;
                $fields['failed_fields'] = [$field];
                $result = \keli_acceptance_cli_failure('adopt-source', new \KeliSourceFixtureEvidenceRejected($fields));
                $this->assertSame($fields['reason'] . '_' . $field, $result['reason']);
                $this->assertSame($fields, $result['source_fixture_evidence']);
                $count++;
            }
        }
        $this->assertSame(38, $count);
    }
}
