<?php

namespace App\Services\ServerMachine;

class SshTerminalException extends \RuntimeException
{
    public function __construct(string $reason, public readonly int $httpStatus = 422)
    {
        parent::__construct($reason);
    }
}
