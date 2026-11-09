<?php

declare(strict_types=1);

use Intakt\Kernel;
use Psr\Log\LoggerInterface;

require dirname(__DIR__, 4).'/vendor/autoload.php';

$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$logger = $container->get(LoggerInterface::class);
$logger->error('Disk on fire outside PHPUnit.');
