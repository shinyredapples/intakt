<?php

declare(strict_types=1);

use Intakt\Kernel;
use Symfony\Component\Clock\ClockInterface;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$kernel = new Kernel('test', false);
$kernel->boot();
echo $kernel->getContainer()->get('test.service_container')->get(ClockInterface::class)::class, PHP_EOL;
