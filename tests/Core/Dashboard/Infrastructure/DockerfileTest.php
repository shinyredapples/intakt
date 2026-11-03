<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

// A worker that is never replaced keeps what every request leaks, so the image must set both restarts and a health check.
#[CoversNothing]
final class DockerfileTest extends TestCase
{
    public function testTheImageSetsAWorkerLoopMaximum(): void
    {
        self::assertMatchesRegularExpression('/^\s+FRANKENPHP_LOOP_MAX=\d+/m', $this->dockerfile());
    }

    public function testTheImageSetsAMemoryCeilingForTheWorker(): void
    {
        self::assertMatchesRegularExpression('/^\s+WORKER_MEMORY_CEILING_MB=\d+/m', $this->dockerfile());
    }

    public function testTheImageDeclaresAHealthCheckOnTheMetricsEndpoint(): void
    {
        self::assertMatchesRegularExpression('/^HEALTHCHECK.*?\/metrics/ms', $this->dockerfile());
    }

    private function dockerfile(): string
    {
        $contents = file_get_contents(__DIR__.'/../../../../Dockerfile');
        self::assertIsString($contents);

        return $contents;
    }
}
