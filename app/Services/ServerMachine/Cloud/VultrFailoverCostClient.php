<?php
namespace App\Services\ServerMachine\Cloud;

class VultrFailoverCostClient extends VultrProvisionClient implements FailoverCostClient
{
    use JsonFailoverCosts;
}
