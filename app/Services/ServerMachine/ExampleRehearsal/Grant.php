<?php

declare(strict_types=1);

namespace App\Services\ServerMachine\ExampleRehearsal;

use App\Models\User;

final readonly class Grant
{
    private function __construct(public string $id, public int $admin, public int $opens, public int $expires) {}

    public static function parse(array $fields): ?self
    {
        $id = $fields['id'] ?? null;
        if (!is_string($id) || !preg_match('/\A[a-f0-9]{32}\z/D', $id)) return null;
        $numbers = [];
        foreach (['admin', 'opens', 'expires'] as $key) {
            $value = $fields[$key] ?? null;
            if (!is_int($value) && !(is_string($value) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $value))) return null;
            $numbers[] = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        }
        [$admin, $opens, $expires] = $numbers;
        if (!$admin || !$opens || !$expires || $expires <= $opens || $expires - $opens > 1800) return null;
        return new self($id, $admin, $opens, $expires);
    }

    public static function forUser(mixed $user): ?self
    {
        if (!$user instanceof User || !$user->is_admin || $user->banned) return null;
        $grant = self::parse((array) config('failover_example', []));
        return $grant && $grant->admin === (int) $user->getKey() && $grant->active(time()) ? $grant : null;
    }

    public function active(int $now): bool { return $now >= $this->opens && $now < $this->expires; }

    public function fingerprint(): string { return hash('sha256', implode(':', [$this->id, $this->admin, $this->opens, $this->expires])); }
}
