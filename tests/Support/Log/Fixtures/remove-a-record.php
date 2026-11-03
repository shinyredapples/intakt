<?php

declare(strict_types=1);

use Intakt\Tests\Support\Log\LogRecords;
use Psr\Log\LogLevel;

require dirname(__DIR__, 4).'/vendor/autoload.php';

exit(new LogRecords($argv[1])->remove(LogLevel::ERROR, $argv[2]) ? 0 : 1);
