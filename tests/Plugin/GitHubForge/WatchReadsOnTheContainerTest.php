<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\Forge\Contract\HeadChecks;
use Intakt\Plugin\Forge\Contract\OpenChanges;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubWatchReads;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

// The test environment binds both reads to a recording, so dev and prod are booted to see what an installed instance wires.
#[CoversNothing]
final class WatchReadsOnTheContainerTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function environments(): iterable
    {
        yield 'dev' => ['dev'];
        yield 'prod' => ['prod'];
    }

    #[DataProvider('environments')]
    public function testTheContainerWiresTheOpenChangesReadToTheGitHubForge(string $environment): void
    {
        self::assertSame(GitHubWatchReads::class, $this->serviceOf(OpenChanges::class, $environment));
    }

    #[DataProvider('environments')]
    public function testTheContainerWiresTheHeadChecksReadToTheGitHubForge(string $environment): void
    {
        self::assertSame(GitHubWatchReads::class, $this->serviceOf(HeadChecks::class, $environment));
    }

    // Asked in a process of its own as `debug:container` does, because only the test container reaches private services and a kernel cannot be booted in two environments in one process.
    private function serviceOf(string $contract, string $environment): string
    {
        $root = dirname(__DIR__, 3);
        $console = new Process([PHP_BINARY, 'bin/console', 'debug:container', $contract, '--format=json'], $root, ['APP_ENV' => $environment, 'APP_DEBUG' => '0', 'APP_CACHE_DIR' => $root.'/var/cache/watch-reads-'.$environment]);
        $console->mustRun();

        preg_match('/"class": "([^"]+)"/', $console->getOutput(), $service);

        return stripslashes($service[1] ?? $console->getOutput());
    }
}
