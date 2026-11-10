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
        self::assertMatchesRegularExpression('/^\s+install-php-extensions \$exts opcache; \\\\$/m', $this->dockerfile());
    }

    // A command substitution inside a command's arguments hides its exit status from set -e; an assignment does not.
    public function testEachImageListsTheMissingExtensionsInAnAssignmentSoAFailingScriptAbortsTheBuild(): void
    {
        foreach (['Dockerfile' => 'install-php-extensions', '.github/ci/Dockerfile' => 'docker-php-ext-install -j"$(nproc)"'] as $file => $install) {
            self::assertMatchesRegularExpression('/^\s+exts="\$\(php -r "\$missing"\)"; \\\\\n\s+'.preg_quote($install, '/').' \$exts /m', $this->file($file), $file);
        }
    }

    public function testEachImageRechecksTheMissingExtensionsInAnAssignmentAfterTheInstall(): void
    {
        foreach (['Dockerfile', '.github/ci/Dockerfile'] as $file) {
            self::assertMatchesRegularExpression('/ \$exts .*\n\s+left="\$\(php -r "\$missing"\)"; \\\\\n\s+test -z "\$left"; \\\\$/m', $this->file($file), $file);
        }
    }

    public function testEachImageRunsTheSameProfileScript(): void
    {
        self::assertSame($this->missingScript('Dockerfile'), $this->missingScript('.github/ci/Dockerfile'));
    }

    public function testTheProfileListFailsOnAComposerJsonThatDoesNotParse(): void
    {
        self::assertNotSame(0, $this->runMissingScript('{"require": {"ext-json": "*"'));
    }

    public function testTheProfileListFailsOnAComposerJsonWithoutRequire(): void
    {
        self::assertNotSame(0, $this->runMissingScript('{"name": "intakt/intakt"}'));
    }

    public function testTheProfileListFailsWhenRequireIsNoObject(): void
    {
        self::assertNotSame(0, $this->runMissingScript('{"require": "ext-json"}'));
    }

    public function testTheProfileListAcceptsTheRepositoryComposerJson(): void
    {
        self::assertSame(0, $this->runMissingScript((string) file_get_contents(__DIR__.'/../../../../composer.json')));
    }

    public function testTheImageReadsTheProfileFromTheComposerJsonItCopies(): void
    {
        self::assertMatchesRegularExpression('/^COPY composer\.json \/usr\/local\/share\/intakt\/$/m', $this->dockerfile());
    }

    public function testTheProfileListNamesEveryRequiredExtensionThatIsNotLoaded(): void
    {
        $composer = '{"require": {"php": ">=8.5", "ext-json": "*", "ext-intakt_absent": "*", "symfony/console": "*"}}';
        $process = $this->missingScriptProcess($composer);

        $process->mustRun();

        self::assertSame('intakt_absent ', $process->getOutput());
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

    public function testTheBaseStagePinsFrankenPhpOnThePhp85LineByDigest(): void
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
            if (1 !== preg_match("/^RUN php( -r '.*PHP_VERSION.*')$/m", (string) file_get_contents(__DIR__.'/../../../../'.$file), $run)) {
                self::fail($file.' has no RUN php -r line that checks PHP_VERSION.');
            }

            exec(PHP_BINARY.strtr($run[1], ['PHP_VERSION' => '"8.5.11"']), result_code: $old);
            exec(PHP_BINARY.strtr($run[1], ['PHP_VERSION' => '"8.5.12"']), result_code: $new);
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
        return $this->file('Dockerfile');
    }

    private function file(string $path): string
    {
        $contents = file_get_contents(__DIR__.'/../../../../'.$path);
        self::assertIsString($contents);

        return $contents;
    }

    private function runMissingScript(string $composer): ?int
    {
        return $this->missingScriptProcess($composer)->run();
    }

    private function missingScriptProcess(string $composer): Process
    {
        return new Process([PHP_BINARY, '-r', str_replace('/usr/local/share/intakt/composer.json', 'php://stdin', $this->missingScript('Dockerfile'))], input: $composer);
    }

    private function missingScript(string $file): string
    {
        if (1 !== preg_match("/^\\s+missing='([^']+)'; \\\\$/m", $this->file($file), $match)) {
            self::fail($file.' names no script that lists the missing profile extensions.');
        }

        return $match[1];
    }
}
