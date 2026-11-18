<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\PhpGate;

use Intakt\Plugin\Gate\Contract\DependencyInstall;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpGate::class)]
#[CoversClass(DependencyInstall::class)]
final class PhpGateTest extends TestCase
{
    public function testWithoutAConfiguredCommandTheDependenciesAreInstalledAndTheFastCheckRuns(): void
    {
        self::assertSame(['composer install --no-interaction --no-progress', 'bin/check --fast'], new PhpGate()->checkSteps(null));
    }

    public function testAnEmptyConfiguredCommandCountsAsNone(): void
    {
        self::assertSame(['composer install --no-interaction --no-progress', 'bin/check --fast'], new PhpGate()->checkSteps(' '));
    }

    public function testAConfiguredCommandIsTheOnlyStep(): void
    {
        self::assertSame(['make check'], new PhpGate()->checkSteps('make check'));
    }

    public function testTheDependenciesComeFromPackagistAndGitHubArchives(): void
    {
        self::assertSame(['repo.packagist.org', 'api.github.com', 'codeload.github.com'], new PhpGate()->registryHosts());
    }

    public function testTheDependencySnapshotIsInstalledFromComposersLockFilesWithScriptsPluginsAndAutoloaderOff(): void
    {
        self::assertEquals(
            new DependencyInstall(['composer.json', 'composer.lock'], 'composer install --no-interaction --no-progress --no-scripts --no-plugins --no-autoloader', 'vendor'),
            new PhpGate()->dependencyInstall(),
        );
    }
}
