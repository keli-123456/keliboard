<?php
namespace App\Services\ServerMachine\Cloud;

class DigitalOceanFailoverCostClient extends DigitalOceanProvisionClient implements FailoverCostClient
{
    use JsonFailoverCosts;
}
