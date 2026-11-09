<?php

namespace App\Services\ServerMachine\Dns;

class DnsRequestFailure extends DnsException
{
    public function __construct(string $reason, public readonly int $retryAfterSeconds = 20, public readonly bool $retryable = true)
    {
        parent::__construct($reason);
    }
}
