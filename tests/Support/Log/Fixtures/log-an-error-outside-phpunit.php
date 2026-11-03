<?php

declare(strict_types=1);

use Intakt\Kernel;
use Psr\Log\LoggerInterface;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$kernel = new Kernel('test', true);
$kernel->boot();
$logger = $kernel->getContainer()->get('test.service_container')->get(LoggerInterface::class);
assert($logger instanceof LoggerInterface);
$logger->error('Disk on fire outside PHPUnit.');
