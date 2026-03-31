<?php

namespace App\Support;

class SupportResult
{
    public function __construct(
        public bool $supported,
        public string $action = 'allow',
        public ?string $reason = null,
        public ?array $matchedRule = null,
    ) {
    }

    public static function allow(?array $rule = null): self
    {
        return new self(true, 'allow', null, $rule);
    }

    public static function drop(string $reason, ?array $rule = null): self
    {
        return new self(false, 'drop', $reason, $rule);
    }
}
