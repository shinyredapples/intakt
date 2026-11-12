<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Core\Job\UseCase\BuildJobImage\GeneratedDockerfile;
use Intakt\Core\Job\UseCase\BuildJobImage\PhpProfile;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Dependabot bumps docker/job/cli/package.json and its lock, so a job image must install exactly what that pull request reviewed.
#[CoversNothing]
final class JobImageCliVersionsTest extends TestCase
{
    private const string MANIFEST = __DIR__.'/../../docker/job/cli/package.json';
    private const string LOCK = __DIR__.'/../../docker/job/cli/package-lock.json';

    public function testTheManifestNamesClaudeCodeAndCodex(): void
    {
        self::assertSame(['@anthropic-ai/claude-code', '@openai/codex'], array_keys(self::manifestVersions()));
    }

    public function testTheGeneratedDockerfileInstallsTheManifestVersions(): void
    {
        $dockerfile = GeneratedDockerfile::for(PhpProfile::fromComposerJson('{"require": {"php": "^8.5"}}'));

        self::assertInstallsTheManifestVersions($dockerfile);
    }

    #[DataProvider('manifestPackages')]
    public function testADockerfileWithAnotherVersionOfAToolFailsTheCheck(string $package): void
    {
        $versions = self::manifestVersions();
        $versions[$package] .= '-other';

        $this->expectException(AssertionFailedError::class);

        self::assertInstallsTheManifestVersions(self::dockerfileInstalling($versions));
    }

    public function testADockerfileWithoutOneOfTheToolsFailsTheCheck(): void
    {
        $this->expectException(AssertionFailedError::class);

        self::assertInstallsTheManifestVersions(self::dockerfileInstalling(array_slice(self::manifestVersions(), 0, 1)));
    }

    public function testTheLockFileLocksTheManifestVersions(): void
    {
        /** @var array{packages: array<string, array{version?: string, dependencies?: array<string, string>}>} $lock */
        $lock = json_decode((string) file_get_contents(self::LOCK), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(self::manifestVersions(), $lock['packages']['']['dependencies'] ?? null, 'The lock file was not written for the dependencies of package.json; run npm install --package-lock-only in docker/job/cli.');
        foreach (self::manifestVersions() as $package => $version) {
            self::assertSame($version, $lock['packages']['node_modules/'.$package]['version'] ?? null, sprintf('The lock file locks another version of %s than package.json names.', $package));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function manifestPackages(): iterable
    {
        foreach (array_keys(self::manifestVersions()) as $package) {
            yield $package => [$package];
        }
    }

    private static function assertInstallsTheManifestVersions(string $dockerfile): void
    {
        self::assertSame(1, preg_match('/^RUN npm install --global (.+?) \\\\$/m', $dockerfile, $install), 'The Dockerfile has no npm install --global step.');
        $installed = [];
        foreach (explode(' ', $install[1]) as $argument) {
            $at = (int) strrpos($argument, '@');
            $installed[substr($argument, 0, $at)] = substr($argument, $at + 1);
        }

        self::assertSame(self::manifestVersions(), $installed, 'The Dockerfile installs other CLI versions than docker/job/cli/package.json names.');
    }

    /**
     * @param array<string, string> $versions
     */
    private static function dockerfileInstalling(array $versions): string
    {
        $arguments = array_map(static fn (string $package, string $version): string => $package.'@'.$version, array_keys($versions), $versions);

        return sprintf("FROM php:8.5-cli\nRUN npm install --global %s \\\n    && npm cache clean --force\n", implode(' ', $arguments));
    }

    /**
     * @return array<string, string>
     */
    private static function manifestVersions(): array
    {
        /** @var array{dependencies: array<string, string>} $manifest */
        $manifest = json_decode((string) file_get_contents(self::MANIFEST), true, flags: JSON_THROW_ON_ERROR);

        return $manifest['dependencies'];
    }
}
