<?php

declare(strict_types=1);

use Doctrine\Bundle\DoctrineBundle\ConnectionFactory;
use Doctrine\DBAL\Platforms\Exception\InvalidPlatformVersion;

try {
    new ConnectionFactory()->createConnection(['url' => 'postgresql://postgres@localhost:5432/app']);
} catch (InvalidPlatformVersion $exception) {
    echo 'A PostgreSQL connection without server_version is refused before it connects: ', $exception->getMessage(), PHP_EOL;

    exit(1);
}

echo 'REPRODUCTION PASSES', PHP_EOL;
