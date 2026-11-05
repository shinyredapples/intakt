<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\TestCase;

abstract class SandboxTestCase extends TestCase
{
    use UsesSandboxes;

    protected function setUp(): void
    {
        putenv('INTAKT_GITLEAKS='.trim((string) shell_exec('command -v gitleaks')));
    }

    protected function tearDown(): void
    {
        $this->removeSandboxes();
    }

    final protected function skipWhenPermissionsAreIgnored(): void
    {
        if (0 === posix_geteuid()) {
            self::markTestSkipped('This user ignores file permissions (superuser).');
        }
    }
}
