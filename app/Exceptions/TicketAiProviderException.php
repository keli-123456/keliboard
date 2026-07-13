<?php

namespace App\Exceptions;

use RuntimeException;

class TicketAiProviderException extends RuntimeException
{
    public function __construct(private readonly string $providerErrorCode)
    {
        parent::__construct('Ticket AI provider error: ' . $providerErrorCode);
    }

    public function errorCode(): string
    {
        return $this->providerErrorCode;
    }
}
