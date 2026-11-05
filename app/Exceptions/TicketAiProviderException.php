<?php

namespace App\Exceptions;

use RuntimeException;

class TicketAiProviderException extends RuntimeException
{
    public function __construct(
        private readonly string $providerErrorCode,
        public readonly ?string $responseIssue = null
    ) {
        parent::__construct('Ticket AI provider error: ' . $providerErrorCode);
    }

    public function errorCode(): string
    {
        return $this->providerErrorCode;
    }
}
