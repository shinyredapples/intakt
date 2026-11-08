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

    public function testTheImageInstallsPcntlSoAConsumerFinishesItsMessageOnStop(): void
    {
        self::assertMatchesRegularExpression('/^RUN install-php-extensions .*\bpcntl\b/m', $this->dockerfile());
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

    public function testTheBaseStagePinsAFrankenPhpImageOnThePhp85LineByDigest(): void
    {
        self::assertTrue($this->pinsFrankenPhpOnPhp85ByDigest($this->dockerfile()));
    }

    public function testAFrankenPhpBumpWithANewPhpPatchStillPassesTheBaseImageCheck(): void
    {
        $bumped = "FROM dunglas/frankenphp:1.14.0-php8.5-trixie@sha256:".str_repeat('a', 64)." AS base\n";

        self::assertTrue($this->pinsFrankenPhpOnPhp85ByDigest($bumped));
    }

    public function testABaseImageWithoutADigestFailsTheBaseImageCheck(): void
    {
        self::assertFalse($this->pinsFrankenPhpOnPhp85ByDigest("FROM dunglas/frankenphp:1.14.0-php8.5-trixie AS base\n"));
    }

    public function testABaseImageOnAnotherPhpLineFailsTheBaseImageCheck(): void
    {
        self::assertFalse($this->pinsFrankenPhpOnPhp85ByDigest('FROM dunglas/frankenphp:1.14.0-php8.4-trixie@sha256:'.str_repeat('a', 64)." AS base\n"));
    }

    public function testTheImageTakesComposerFromAStagePinnedByTagAndDigest(): void
    {
        self::assertMatchesRegularExpression('/^FROM composer:\d+\.\d+\.\d+@sha256:[0-9a-f]{64} AS composer$/m', $this->dockerfile());
        self::assertMatchesRegularExpression('/^COPY --from=composer \/usr\/bin\/composer /m', $this->dockerfile());
    }

    public function testNoCopyFromLineNamesAnImageForComposer(): void
    {
        self::assertDoesNotMatchRegularExpression('/^COPY --from=\S*composer\S*\/\S+/mi', str_replace('COPY --from=composer /usr/bin/composer', '', $this->dockerfile()));
    }

    public function testTheBaseStageRunsThePhpFloorCheck(): void
    {
        self::assertMatchesRegularExpression('/^RUN intakt-check-php-floor$/m', $this->dockerfile());
    }

    public function testTheBaseStageIsTheFirstFromSoTheSmokeTestReadsItsTag(): void
    {
        self::assertMatchesRegularExpression('/\A(?:#[^\n]*\n)*FROM dunglas\/frankenphp:\S+ AS base$/m', $this->dockerfile());
    }

    private function pinsFrankenPhpOnPhp85ByDigest(string $dockerfile): bool
    {
        return 1 === preg_match('/^FROM dunglas\/frankenphp:\d+\.\d+\.\d+-php8\.5-trixie@sha256:[0-9a-f]{64} AS base$/m', $dockerfile);
    }

    private function dockerfile(): string
    {
        $contents = file_get_contents(__DIR__.'/../../../../Dockerfile');
        self::assertIsString($contents);

        return $contents;
    }
}
