<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;

$path = tempnam(sys_get_temp_dir(), 'dbal-failed-commit-');
$writer = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
$writer->executeStatement('CREATE TABLE item (id INTEGER PRIMARY KEY)');
$writer->executeStatement('PRAGMA busy_timeout = 0');

// A reader inside a transaction holds SQLite's shared lock, so the writer's COMMIT is refused with "database is locked".
$reader = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $path]);
$reader->beginTransaction();
$reader->fetchOne('SELECT COUNT(*) FROM item');

$writer->beginTransaction();
$writer->insert('item', ['id' => 1]);

try {
    $writer->commit();
} catch (LockWaitTimeoutException $refused) {
    echo 'COMMIT refused: ', $refused::class, PHP_EOL;
}

$native = $writer->getNativeConnection();
assert($native instanceof PDO);
$dbalActive = $writer->isTransactionActive();
$driverActive = $native->inTransaction();
echo 'DBAL transaction active: ', var_export($dbalActive, true), ', driver transaction active: ', var_export($driverActive, true), PHP_EOL;

$reader->rollBack();
unlink($path);

if ($dbalActive !== $driverActive) {
    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
