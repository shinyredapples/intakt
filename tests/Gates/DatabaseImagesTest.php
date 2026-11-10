<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// Dependabot reads image tags from Dockerfiles only, so the persistence job takes its database images from one.
#[CoversNothing]
final class DatabaseImagesTest extends TestCase
{
    private const string IMAGES = __DIR__.'/../../.github/ci/databases.Dockerfile';

    public function testTheDatabaseImagesFileNamesOneImagePerEngine(): void
    {
        $matches = [];
        preg_match_all('#^FROM (mysql|mariadb|postgres):\S+ AS (mysql|mariadb|postgresql)$#m', (string) file_get_contents(self::IMAGES), $matches);

        self::assertSame(['mysql', 'mariadb', 'postgresql'], $matches[2]);
    }

    public function testNoWorkflowNamesADatabaseImageTagOfItsOwn(): void
    {
        $tagged = preg_grep('#\b(mysql|mariadb|postgres):[\d.]+(?![\d/])#', Workflow::allLines());

        self::assertSame([], $tagged);
    }
}
