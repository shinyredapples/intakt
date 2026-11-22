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

    // Owner decision of #1900 (D2): the lock takes this beta, so no component may stay below it.
    private const string TAKEN_RELEASE = 'v8.2.0-BETA2';

    private const string FIRST_RELEASE = 'v8.2.0-BETA1';

    // Kept on BETA1 because Symfony tagged 8.2.0-BETA2 only for the components that changed: these have no BETA2 on Packagist (checked 2026-11-14).
    private const array WITHOUT_TAKEN_RELEASE = [
        'symfony/asset',
        'symfony/clock',
        'symfony/css-selector',
        'symfony/dom-crawler',
        'symfony/dotenv',
        'symfony/error-handler',
        'symfony/event-dispatcher',
        'symfony/filesystem',
        'symfony/options-resolver',
        'symfony/password-hasher',
        'symfony/process',
        'symfony/psr-http-message-bridge',
        'symfony/security-csrf',
        'symfony/stopwatch',
        'symfony/string',
        'symfony/uid',
        'symfony/var-exporter',
        'symfony/web-link',
    ];

    public function testEverySymfonyComponentInTheLockIsAtTheTakenBetaOrLater(): void
    {
        self::assertNotSame([], $this->lockedComponents());
        self::assertSame([], $this->behind($this->lockedComponents()));
    }

    public function testAComponentWithATakenBetaTagIsBehindOnTheFirstBeta(): void
    {
        self::assertSame(['symfony/framework-bundle v8.2.0-BETA1'], $this->behind(['symfony/framework-bundle' => 'v8.2.0-BETA1']));
    }

    public function testAComponentWithoutATakenBetaTagMayStayOnTheFirstBeta(): void
    {
        self::assertSame([], $this->behind(['symfony/asset' => 'v8.2.0-BETA1', 'symfony/process' => 'v8.2.0']));
    }

    public function testAComponentWithoutATakenBetaTagIsBehindOnAnEarlierMinor(): void
    {
        self::assertSame(['symfony/asset v8.1.9', 'symfony/clock v8.2.0-dev'], $this->behind(['symfony/asset' => 'v8.1.9', 'symfony/clock' => 'v8.2.0-dev']));
    }

    public function testEveryComponentAllowedToStayOnTheFirstBetaIsInTheLock(): void
    {
        self::assertSame([], array_values(array_diff(self::WITHOUT_TAKEN_RELEASE, array_keys($this->lockedComponents()))));
    }

    public function testEveryRangedSymfonyRequirementAsksForEightTwo(): void
    {
        $manifest = $this->json('composer.json');
        $wrong = [];
        foreach (['require', 'require-dev'] as $section) {
            self::assertIsArray($manifest[$section]);
            foreach ($manifest[$section] as $name => $constraint) {
                if (is_string($name) && str_starts_with($name, 'symfony/') && is_string($constraint) && str_starts_with($constraint, '^8.') && '^'.self::MINOR !== $constraint) {
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

    /**
     * @param array<string, string> $components
     *
     * @return list<string>
     */
    private function behind(array $components): array
    {
        $behind = [];
        foreach ($components as $name => $version) {
            $floor = in_array($name, self::WITHOUT_TAKEN_RELEASE, true) ? self::FIRST_RELEASE : self::TAKEN_RELEASE;
            if (!str_starts_with($version, 'v'.self::MINOR.'.') || Comparator::lessThan($this->normalized($version), $this->normalized($floor))) {
                $behind[] = $name.' '.$version;
            }
        }

        return $behind;
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
