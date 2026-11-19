<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ConnectionException;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;

$file = (string) tempnam(sys_get_temp_dir(), 'dbal-commit-');
$connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'path' => $file]);
$connection->executeStatement('CREATE TABLE t (id INTEGER)');
$connection->executeStatement('PRAGMA busy_timeout = 0');

$reader = new PDO('sqlite:'.$file);
$reader->beginTransaction();
$reader->query('SELECT COUNT(*) FROM t');

$thrown = null;

try {
    $connection->transactional(static fn (Connection $inner): int|string => $inner->insert('t', ['id' => 1]));
} catch (Exception $failure) {
    $thrown = $failure;
    echo 'transactional() threw ', $failure::class, ': ', $failure->getMessage(), PHP_EOL;
}

$reader->rollBack();
$native = $connection->getNativeConnection();
$nativeOpen = $native instanceof PDO && $native->inTransaction();
$rows = $connection->fetchOne('SELECT COUNT(*) FROM t');
echo 'DBAL transaction active: ', var_export($connection->isTransactionActive(), true), ', PDO transaction active: ', var_export($nativeOpen, true), ', rows seen: ', $rows, PHP_EOL;
unlink($file);

if (null === $thrown || $thrown instanceof ConnectionException || $nativeOpen) {
    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
