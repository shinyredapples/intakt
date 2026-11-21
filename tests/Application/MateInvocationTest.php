<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class MateInvocationTest extends TestCase
{
    private const string ROOT = __DIR__.'/../..';

    public function testTheToolListOffersTheServerContainerAndProfilerTools(): void
    {
        $tools = $this->mate('tools:list');

        self::assertIsArray($tools['tools']);
        self::assertArrayHasKey('server-info', $tools['tools']);
        self::assertArrayHasKey('symfony-services', $tools['tools']);
        self::assertArrayHasKey('symfony-dotenv-check', $tools['tools']);
        self::assertArrayHasKey('symfony-profiler-list', $tools['tools']);
        self::assertArrayHasKey('symfony-profiler-get', $tools['tools']);
    }

    public function testTheServerInfoToolReportsTheRuntimeOfThisInstall(): void
    {
        $info = $this->mate('tools:call', 'server-info');

        self::assertSame(PHP_VERSION, $info['php_version']);
    }

    public function testTheProfilerToolsReadTheProfilesOfTheCacheThatAppCacheDirNames(): void
    {
        $cache = sys_get_temp_dir().'/intakt-mate-profiler-'.bin2hex(random_bytes(6));
        $profile = new Profile('a1b2c3');
        $profile->setMethod('GET');
        $profile->setUrl('http://localhost/');
        // Because FileProfilerStorage purges profiles older than two days on one write in ten, a fixed timestamp failed once it aged.
        $profile->setTime(new NativeClock()->now()->getTimestamp());
        $profile->setStatusCode(200);

        try {
            $storage = new FileProfilerStorage('file:'.$cache.'/dev/profiler');
            $storage->write($profile);
            // The purge of expired profiles runs on a random write; run it here so every run takes that path.
            new ReflectionMethod($storage, 'removeExpiredProfiles')->invoke($storage);

            $profiles = $this->mateWith(['APP_CACHE_DIR' => $cache], 'tools:call', 'symfony-profiler-list');
        } finally {
            new Filesystem()->remove($cache);
        }

        self::assertStringContainsString('"a1b2c3"', json_encode($profiles, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<mixed>
     */
    private function mate(string ...$arguments): array
    {
        return $this->mateWith([], ...$arguments);
    }

    /**
     * @param array<string, string> $environment
     *
     * @return array<mixed>
     */
    private function mateWith(array $environment, string ...$arguments): array
    {
        $process = new Process([PHP_BINARY, 'vendor/bin/mate', ...$arguments, '--format=json'], self::ROOT, $environment);
        $process->mustRun();
        $output = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($output);

        return $output;
    }
}
