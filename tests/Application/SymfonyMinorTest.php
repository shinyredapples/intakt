<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Composer\Semver\Comparator;
use Composer\Semver\VersionParser;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class SymfonyMinorTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    private const string MINOR = '8.2';

    // Symfony tags only the components that changed since the last release, so an unchanged one stays on BETA1.
    private const string FIRST_RELEASE = 'v8.2.0-BETA1';

    // The beta the owner took for the framework itself; the components it is built from follow it.
    private const string TAKEN_RELEASE = 'v8.2.0-BETA2';

    public function testEverySymfonyComponentInTheLockIsAnEightTwoRelease(): void
    {
        $behind = [];
        foreach ($this->lockedComponents() as $name => $version) {
            if (!$this->isMinorRelease($version) || Comparator::lessThan($this->normalized($version), $this->normalized(self::FIRST_RELEASE))) {
                $behind[] = $name.' '.$version;
            }
        }

        self::assertNotSame([], $this->lockedComponents());
        self::assertSame([], $behind);
    }

    public function testTheFrameworkIsLockedAtTheTakenBetaOrLater(): void
    {
        $version = $this->lockedComponents()['symfony/framework-bundle'] ?? '';

        self::assertTrue($this->isMinorRelease($version), $version);
        self::assertTrue(Comparator::greaterThanOrEqualTo($this->normalized($version), $this->normalized(self::TAKEN_RELEASE)), $version);
    }

    public function testEveryRangedSymfonyRequirementAsksForEightTwo(): void
    {
        $manifest = $this->json('composer.json');
        $wrong = [];
        foreach (['require', 'require-dev'] as $section) {
            self::assertIsArray($manifest[$section]);
            foreach ($manifest[$section] as $name => $constraint) {
                if (\is_string($name) && str_starts_with($name, 'symfony/') && \is_string($constraint) && str_starts_with($constraint, '^8.') && '^'.self::MINOR !== $constraint) {
                    $wrong[] = $name.' '.$constraint;
                }
            }
        }

        self::assertSame([], $wrong);
        self::assertIsArray($manifest['extra']);
        self::assertIsArray($manifest['extra']['symfony']);
        self::assertSame('^'.self::MINOR, $manifest['extra']['symfony']['require']);
    }

    /**
     * @return array<string, string>
     */
    private function lockedComponents(): array
    {
        $lock = $this->json('composer.lock');
        self::assertIsArray($lock['packages']);
        self::assertIsArray($lock['packages-dev']);

        $components = [];
        foreach ([...$lock['packages'], ...$lock['packages-dev']] as $package) {
            self::assertIsArray($package);
            self::assertIsString($package['name']);
            self::assertIsString($package['version']);
            if (str_starts_with($package['name'], 'symfony/') && str_starts_with($package['version'], 'v8.')) {
                $components[$package['name']] = $package['version'];
            }
        }

        return $components;
    }

    private function isMinorRelease(string $version): bool
    {
        return str_starts_with($version, 'v'.self::MINOR.'.');
    }

    private function normalized(string $version): string
    {
        return new VersionParser()->normalize($version);
    }

    /**
     * @return array<mixed>
     */
    private function json(string $file): array
    {
        $decoded = json_decode((string) file_get_contents(self::ROOT.'/'.$file), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
