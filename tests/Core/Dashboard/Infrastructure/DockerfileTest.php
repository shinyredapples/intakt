<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

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

    public function testTheProfileTheImageInstallsHasPcntlSoAConsumerFinishesItsMessageOnStop(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__.'/../../../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

        self::assertIsArray($composer);
        self::assertIsArray($composer['require']);
        self::assertArrayHasKey('ext-pcntl', $composer['require']);
    }

    public function testTheImageInstallsTheProfileExtensionsAndOnlyOpcacheBeside(): void
    {
        self::assertMatchesRegularExpression('/^\s+install-php-extensions \$\(php -r "\$missing"\) opcache; \\\\$/m', $this->dockerfile());
    }

    public function testTheImageReadsTheProfileFromTheComposerJsonItCopies(): void
    {
        self::assertMatchesRegularExpression('/^COPY composer\.json \/usr\/local\/share\/intakt\/$/m', $this->dockerfile());
    }

    public function testTheProfileListNamesEveryRequiredExtensionThatIsNotLoaded(): void
    {
        $composer = '{"require": {"php": ">=8.5", "ext-json": "*", "ext-intakt_absent": "*", "symfony/console": "*"}}';
        $process = new Process([PHP_BINARY, '-r', str_replace('/usr/local/share/intakt/composer.json', 'php://stdin', $this->missingScript())], input: $composer);

        $process->mustRun();

        self::assertSame('intakt_absent ', $process->getOutput());
    }

    public function testTheBuildFailsWhenAProfileExtensionIsStillMissingAfterTheInstall(): void
    {
        self::assertMatchesRegularExpression('/install-php-extensions \$\(php -r "\$missing"\).*\n\s+test -z "\$\(php -r "\$missing"\)"; \\\\$/m', $this->dockerfile());
    }

    public function testTheBuildFailsWhenOpcacheIsNotLoaded(): void
    {
        self::assertMatchesRegularExpression('/^\s+php -r \'if \(!extension_loaded\("Zend OPcache"\)\) \{ fwrite\(STDERR, "missing PHP extension opcache\\\\n"\); exit\(1\); \}\'$/m', $this->dockerfile());
    }

    public function testTheImageKeepsProductionSettingsWithoutTheCheckIni(): void
    {
        self::assertDoesNotMatchRegularExpression('/^COPY .*check\.ini/m', $this->dockerfile());
        self::assertStringNotContainsString('PHP_INI_SCAN_DIR', $this->dockerfile());
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
        self::assertMatchesRegularExpression('/^FROM dunglas\/frankenphp:[\d.]+-php8\.5-trixie@sha256:[0-9a-f]{64} AS base$/m', $this->dockerfile());
    }

    // The floor is 1.13.4 because it is the first FrankenPHP release with the worker state-leak fixes.
    public function testEachImageRunsFrankenPhp1134OrLater(): void
    {
        foreach (['Dockerfile', '.github/ci/Dockerfile'] as $file) {
            self::assertTrue($this->runsFrankenPhp1134OrLater((string) file_get_contents(__DIR__.'/../../../../'.$file)), $file);
        }
    }

    public function testAFrankenPhpBaseBelow1134IsRefused(): void
    {
        self::assertFalse($this->runsFrankenPhp1134OrLater($this->withFrankenPhp('1.13.3')));
    }

    public function testANewerFrankenPhpBaseIsAccepted(): void
    {
        self::assertTrue($this->runsFrankenPhp1134OrLater($this->withFrankenPhp('1.14.0')));
    }

    public function testEachImageBuildFailsBelowPhp8512(): void
    {
        foreach (['Dockerfile', '.github/ci/Dockerfile'] as $file) {
            preg_match("/^RUN php( -r '.*PHP_VERSION.*')$/m", (string) file_get_contents(__DIR__.'/../../../../'.$file), $run);
            exec(PHP_BINARY.strtr($run[1] ?? ' -v', ['PHP_VERSION' => '"8.5.11"']), result_code: $old);
            exec(PHP_BINARY.strtr($run[1] ?? ' -v', ['PHP_VERSION' => '"8.5.12"']), result_code: $new);
            self::assertSame([1, 0], [$old, $new], $file);
        }
    }

    private function runsFrankenPhp1134OrLater(string $dockerfile): bool
    {
        return 1 === preg_match('/^FROM dunglas\/frankenphp:([\d.]+)-php8\.5-trixie@sha256:[0-9a-f]{64}\b/m', $dockerfile, $match)
            && version_compare($match[1], '1.13.4', '>=');
    }

    private function withFrankenPhp(string $version): string
    {
        return 'FROM dunglas/frankenphp:'.$version.'-php8.5-trixie@sha256:'.str_repeat('0', 64)." AS base\n";
    }

    private function dockerfile(): string
    {
        $contents = file_get_contents(__DIR__.'/../../../../Dockerfile');
        self::assertIsString($contents);

        return $contents;
    }

    private function missingScript(): string
    {
        if (1 !== preg_match("/^\\s+missing='([^']+)'; \\\\$/m", $this->dockerfile(), $match)) {
            self::fail('The Dockerfile names no script that lists the missing profile extensions.');
        }

        return $match[1];
    }
}
