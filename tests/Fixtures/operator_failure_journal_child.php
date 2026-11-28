<?php

declare(strict_types=1);

use App\Services\ServerMachine\Cloud\MachineFailoverOperatorFailureJournal;
use App\Services\ServerMachine\Cloud\MachineFailoverQueue;
use Illuminate\Container\Container;
use Illuminate\Queue\Jobs\SyncJob;

$root = dirname(__DIR__, 2);
$loader = require $root . '/vendor/autoload.php';
$loader->setPsr4('App\\', [$root . '/app']);
$app = new Container();
$job = new SyncJob($app, json_encode(['uuid' => '07129724-2988-445f-b6cb-096850f78d38'], JSON_THROW_ON_ERROR),
    MachineFailoverQueue::CONNECTION, MachineFailoverQueue::OPERATOR_NAME);
$journal = new MachineFailoverOperatorFailureJournal($argv[1]);
$journal->begin($job);
sleep(45);
