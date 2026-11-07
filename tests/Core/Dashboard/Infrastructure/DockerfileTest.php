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

    public function testTheImageGivesTheMercureIssuerADefault(): void
    {
        self::assertMatchesRegularExpression('/^\s+MERCURE_JWT_ISSUER=http:\/\/localhost:7781/m', $this->dockerfile());
    }

    public function testTheImageDeclaresAHealthCheckOnTheMetricsEndpoint(): void
    {
        self::assertMatchesRegularExpression('/^HEALTHCHECK.*?\/metrics/ms', $this->dockerfile());
    }

    public function testTheImageSetsHowManyQueueConsumersRun(): void
    {
        self::assertMatchesRegularExpression('/^\s+MESSENGER_CONSUMERS=\d+/m', $this->dockerfile());
    }

    public function testTheImageInstallsTheExtensionsTheProfileLists(): void
    {
        self::assertMatchesRegularExpression('/^RUN install-php-extensions \$\(php profile-extensions\.php\)/m', $this->dockerfile());
    }

    public function testTheProfileListsPcntlSoAConsumerFinishesItsMessageOnStop(): void
    {
        self::assertStringContainsString('"ext-pcntl": "*"', (string) file_get_contents(__DIR__.'/../../../../composer.json'));
    }

    public function testTheImageInstallsGitForTheForgeAndTheSampleRepository(): void
    {
        self::assertMatchesRegularExpression('/^\s+&& apt-get install -y --no-install-recommends git\b/m', $this->dockerfile());
    }

    public function testTheImagePointsReviewersAtTheMcpServerOnItsOwnPort(): void
    {
        self::assertMatchesRegularExpression('/^\s+SERVER_PORT=7781\b/m', $this->dockerfile());
        self::assertMatchesRegularExpression('/^\s+REVIEW_MCP_BASE_URL=http:\/\/localhost:7781\b/m', $this->dockerfile());
    }

    public function testTheBaseStagePinsFrankenPhpOnPhp8512ByDigest(): void
    {
        self::assertMatchesRegularExpression('/^FROM dunglas\/frankenphp:1\.13\.4-php8\.5\.12-trixie@sha256:[0-9a-f]{64} AS base$/m', $this->dockerfile());
    }

    private function dockerfile(): string
    {
        $contents = file_get_contents(__DIR__.'/../../../../Dockerfile');
        self::assertIsString($contents);

        return $contents;
    }
}
