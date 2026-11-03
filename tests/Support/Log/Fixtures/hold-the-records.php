<?php

declare(strict_types=1);

// The lock lives in its own process, because a process the test starts inherits the test's open files and with them its lock.

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'];

$records = fopen($arguments[1], 'a');
if (false === $records || !flock($records, LOCK_EX)) {
    exit(1);
}

echo 'locked'.PHP_EOL;
fgets(STDIN);
fwrite($records, $arguments[2].PHP_EOL);
fclose($records);
