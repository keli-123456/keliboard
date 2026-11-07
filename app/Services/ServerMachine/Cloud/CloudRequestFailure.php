<?php
namespace App\Services\ServerMachine\Cloud;

class CloudRequestFailure extends CloudException
{
    public function __construct(public readonly array $decision)
    {
        parent::__construct($decision['code']);
    }
}
