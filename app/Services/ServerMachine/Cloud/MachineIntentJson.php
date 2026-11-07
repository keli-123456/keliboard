<?php
declare(strict_types=1);

namespace App\Services\ServerMachine\Cloud;

final class MachineIntentJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::ordered($value), JSON_THROW_ON_ERROR);
    }
    public static function same(mixed $left, mixed $right): bool
    {
        return hash_equals(self::encode($left), self::encode($right));
    }
    private static function ordered(mixed $value): mixed
    {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) return array_map(self::ordered(...), $value);
        ksort($value, SORT_STRING); $object = new \stdClass();
        foreach ($value as $key => $child) $object->{(string) $key} = self::ordered($child);
        return $object;
    }
}
