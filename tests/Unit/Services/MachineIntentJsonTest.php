<?php
declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\ServerMachine\Cloud\MachineIntentJson as Intent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MachineIntentJsonTest extends TestCase
{
    public function test_object_key_order_is_not_part_of_a_persisted_intents_identity(): void
    {
        $left = ['release' => ['id' => 1, 'sha' => 'synthetic'], 'nodes' => [['id' => 2, 'port' => 443]], 'enabled' => false];
        $right = ['enabled' => false, 'nodes' => [['port' => 443, 'id' => 2]], 'release' => ['sha' => 'synthetic', 'id' => 1]];
        $this->assertTrue(Intent::same($left, $right)); $this->assertSame(Intent::encode($left), Intent::encode($right));
    }
    #[DataProvider('changedValues')]
    public function test_values_types_and_list_order_still_change_the_identity(mixed $left, mixed $right): void
    {
        $this->assertFalse(Intent::same($left, $right));
    }
    public static function changedValues(): array
    {
        return [[['id' => 1], ['id' => '1']], [['enabled' => true], ['enabled' => 1]], [['id' => null], []],
            [['sha' => 'a'], ['sha' => 'b']], [['nodes' => [1, 2]], ['nodes' => [2, 1]]], [[0 => 'a', 1 => 'b'], [1 => 'b', 0 => 'a']]];
    }
    public function test_empty_array_is_not_an_empty_object(): void { $this->assertFalse(Intent::same([], (object) [])); }
}
