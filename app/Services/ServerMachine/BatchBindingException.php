<?php

namespace App\Services\ServerMachine;

use RuntimeException;

class BatchBindingException extends RuntimeException
{
    public function __construct(string $message, int $responseCode = 422)
    {
        parent::__construct($message, $responseCode);
    }
}
