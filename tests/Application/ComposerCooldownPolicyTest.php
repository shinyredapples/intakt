<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// The cooldown policy of composer.json applied by `composer update` itself, on an inline package repository so the run stays offline.
#[CoversNothing]
final class ComposerCooldownPolicyTest extends TestCase
{
    // Because Composer compares the release time with its own clock, a release in the far future stays inside the period.
    private const string INSIDE_THE_PERIOD = '2999-01-01T00:00:00+00:00';

    private const string OUTSIDE_THE_PERIOD = '2020-06-01T00:00:00+00:00';

    // Because the fixture serves its own packages, a proxy nobody listens on turns any request to Packagist into a failed test.
    private const array OFFLINE = ['http_proxy' => 'http://127.0.0.1:9', 'https_proxy' => 'http://127.0.0.1:9', 'HTTP_PROXY' => 'http://127.0.0.1:9', 'HTTPS_PROXY' => 'http://127.0.0.1:9', 'no_proxy' => '', 'NO_PROXY' => ''];

    private string $directory;

    public function testAnUpdateWithTheRepositoryPolicyLeavesOutAReleaseInsideTheCooldown(): void
    {
        $this->projectRequiringAPackageReleasedAt(self::INSIDE_THE_PERIOD);

        self::assertSame('1.0.0', $this->lockedVersionAfterAnUpdate());
    }

    public function testAnUpdateWithTheRepositoryPolicyLocksAReleaseOlderThanTheCooldown(): void
    {
        $this->projectRequiringAPackageReleasedAt(self::OUTSIDE_THE_PERIOD);

        self::assertSame('1.1.0', $this->lockedVersionAfterAnUpdate());
    }

    public function testTheRepositoryPolicyExceptsOnlyTheSymfonyComponentsAtTheOneTimeVersion(): void
    {
        $policy = $this->repositoryPolicy();
        self::assertIsArray($policy);
        self::assertIsArray($policy['cooldown'] ?? null);

        $ignore = $policy['cooldown']['ignore'] ?? null;
        self::assertIsArray($ignore);
        self::assertSame(['symfony/*'], array_keys($ignore));
        self::assertIsArray($ignore['symfony/*']);
        self::assertSame('8.2.0-BETA2', $ignore['symfony/*']['constraint'] ?? null);
        self::assertSame('3 days', $policy['cooldown']['period'] ?? null);
        self::assertTrue($policy['cooldown']['block'] ?? null);
        self::assertSame('fail', $policy['cooldown']['audit'] ?? null);
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-cooldown-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    private function projectRequiringAPackageReleasedAt(string $newerRelease): void
    {
        $project = [
            'name' => 'intakt/cooldown-fixture',
            'repositories' => [
                ['packagist.org' => false],
                ['type' => 'package', 'package' => [$this->release('1.0.0', '2020-01-01T00:00:00+00:00'), $this->release('1.1.0', $newerRelease)]],
            ],
            'require' => ['intakt/fresh-package' => '^1.0'],
            'config' => ['policy' => $this->repositoryPolicy()],
        ];

        new Filesystem()->dumpFile($this->directory.'/composer.json', json_encode($project, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    private function release(string $version, string $time): array
    {
        return ['name' => 'intakt/fresh-package', 'version' => $version, 'time' => $time, 'dist' => ['type' => 'zip', 'url' => 'file:///nonexistent.zip']];
    }

    private function repositoryPolicy(): mixed
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($composer);
        self::assertIsArray($composer['config'] ?? null);

        return $composer['config']['policy'] ?? self::fail('composer.json sets no config.policy');
    }

    private function lockedVersionAfterAnUpdate(): string
    {
        $update = new Process(
            ['composer', 'update', '--no-install', '--no-plugins', '--no-scripts', '--no-audit', '--no-interaction'],
            $this->directory,
            ['COMPOSER_HOME' => $this->directory.'/home', 'COMPOSER_CACHE_DIR' => $this->directory.'/cache', 'COMPOSER' => false, ...self::OFFLINE],
        );
        $update->run();
        self::assertTrue($update->isSuccessful(), $update->getErrorOutput());

        $lock = json_decode((string) file_get_contents($this->directory.'/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($lock);
        self::assertIsArray($lock['packages'] ?? null);
        $package = array_first($lock['packages']);
        self::assertIsArray($package, 'the update locked no package');
        self::assertIsString($package['version'] ?? null);

        return $package['version'];
    }
}
