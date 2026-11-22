<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\PhpGate;

use Intakt\Plugin\Gate\Contract\DependencyStep;
use Intakt\Plugin\PhpGate\Infrastructure\PhpGate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpGate::class)]
#[UsesClass(DependencyStep::class)]
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

    public function testTheComposerLockInputsAreTheManifestTheLockFileAndThePhpVersionOfTheJobImage(): void
    {
        $step = $this->composerStep(null);

        self::assertSame(['composer.json', 'composer.lock'], $step->lockFiles);
        self::assertSame("php -r 'echo PHP_VERSION;'", $step->platformProbe);
    }

    public function testTheCleanInstallTakesOnlyTheLockedPackagesWithScriptsPluginsAndTheAutoloaderOff(): void
    {
        self::assertStringContainsString(
            ' && { intakt_composer=0; composer install --no-interaction --no-progress --no-scripts --no-plugins --no-autoloader || intakt_composer=$?; ',
            $this->composerStep(null)->install,
        );
    }

    public function testTheAutoloadAndRuntimeFilesAreGeneratedApartFromTheInstallFromTheChecksOwnTree(): void
    {
        self::assertSame('composer install --no-interaction --no-progress', $this->composerStep(null)->generate);
    }

    public function testTheInstallACopiedSnapshotReplacesIsTheFirstOfTheGatesOwnCheckSteps(): void
    {
        self::assertSame(new PhpGate()->checkSteps(null)[0], $this->composerStep(null)->checkInstall);
    }

    public function testAnEmptyConfiguredCommandStillNamesTheComposerLockInputs(): void
    {
        self::assertSame(['composer.json', 'composer.lock'], $this->composerStep(' ')->lockFiles);
    }

    public function testAProjectWithItsOwnCheckCommandReportsNoLockInputsSoItInstallsEveryTime(): void
    {
        self::assertNull(new PhpGate()->dependencyStep('make check'));
    }

    private function composerStep(?string $configuredCommand): DependencyStep
    {
        $step = new PhpGate()->dependencyStep($configuredCommand);
        self::assertNotNull($step);

        return $step;
    }
}
