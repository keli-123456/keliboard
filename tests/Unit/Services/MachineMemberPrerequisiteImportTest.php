<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use Keli\Acceptance\MemberCliAuthority;
use Keli\Acceptance\MemberPrerequisiteImport;
use PHPUnit\Framework\TestCase;

final class MachineMemberPrerequisiteImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once __DIR__ . '/../../../tools/machine-acceptance/member_cli_authority.php';
        require_once __DIR__ . '/../../../tools/machine-acceptance/member_prerequisite_import.php';
    }

    private function material(): array
    {
        return ['schema' => 1, 'run_id' => MemberCliAuthority::RUN,
            'linode' => ['api_token' => 'synthetic-token'],
            'aliyun' => ['access_key_id' => 'synthetic-id', 'access_key_secret' => 'synthetic-secret'], 'baseline_ids' => ['91', '92']];
    }

    public function test_only_exact_material_and_distinct_baseline_ids_are_accepted(): void
    {
        $material = $this->material();
        self::assertSame($material, MemberPrerequisiteImport::material($material));
        $reordered = array_reverse($material, true);
        $reordered['aliyun'] = array_reverse($material['aliyun'], true);
        self::assertEquals($material, MemberPrerequisiteImport::material($reordered));
        $bad = [array_replace($material, ['schema' => 2]), array_replace($material, ['run_id' => 'foreign']),
            $material + ['command' => 'arbitrary'], array_replace($material, ['linode' => ['api_token' => "bad\nsecret"]]),
            array_replace($material, ['aliyun' => ['access_key_id' => 'synthetic-id', 'access_key_secret' => 'short']])];
        foreach ([['91', '91'], [91], ['0'], ['-1'], ['91/other'], ['0001'], range(1, 997)] as $ids)
            $bad[] = array_replace($material, ['baseline_ids' => $ids]);
        foreach ($bad as $value) {
            try { MemberPrerequisiteImport::material($value); self::fail('invalid import material accepted'); }
            catch (\RuntimeException $error) {
                self::assertMatchesRegularExpression('/^member_import_(material|baseline)_invalid$/D', $error->getMessage());
                self::assertStringNotContainsString('synthetic-secret', $error->getMessage());
            }
        }
    }
}
