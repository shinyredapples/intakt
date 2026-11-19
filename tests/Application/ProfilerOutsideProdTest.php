<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversNothing;
use Symfony\Component\Process\Process;

// The profiler is a development tool: dev and test hold it to measure a request, the production container must not hold it at all (#2721, D1).
#[CoversNothing]
final class ProfilerOutsideProdTest extends KernelTestCase
{
    private const string PROFILER = 'profiler';

    public function testTheTestContainerHoldsTheProfiler(): void
    {
        self::assertTrue(self::getContainer()->has(self::PROFILER));
    }

    public function testTheProductionContainerHoldsNoProfilerService(): void
    {
        $services = $this->servicesOfTheProductionContainer();

        self::assertArrayHasKey('router', $services);
        self::assertArrayNotHasKey(self::PROFILER, $services);
        self::assertArrayNotHasKey('profiler_listener', $services);
    }

    // Asked in a process of its own as `debug:container` does, because a kernel cannot be booted in two environments in one process.
    /**
     * @return array<mixed>
     */
    private function servicesOfTheProductionContainer(): array
    {
        $root = dirname(__DIR__, 2);
        $console = new Process([PHP_BINARY, 'bin/console', 'debug:container', '--format=json'], $root, ['APP_ENV' => 'prod', 'APP_DEBUG' => '0', 'APP_CACHE_DIR' => $root.'/var/cache/profiler-prod']);
        $console->mustRun();
        $listing = json_decode($console->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($listing);
        self::assertIsArray($listing['definitions']);
        self::assertIsArray($listing['aliases']);

        return [...$listing['definitions'], ...$listing['aliases']];
    }
}
