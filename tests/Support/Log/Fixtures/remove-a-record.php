<?php

declare(strict_types=1);

use Intakt\Tests\Support\Log\LogRecords;
use Psr\Log\LogLevel;

require dirname(__DIR__, 4).'/vendor/autoload.php';

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'];

exit(new LogRecords($arguments[1])->remove(LogLevel::ERROR, $arguments[2]) ? 0 : 1);
