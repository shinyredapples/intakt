<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job\UseCase\BuildJobImage;

use Intakt\Core\Job\UseCase\BuildJobImage\GeneratedDockerfile;
use Intakt\Core\Job\UseCase\BuildJobImage\JobImageNotBuilt;
use Intakt\Core\Job\UseCase\BuildJobImage\PhpProfile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversClass(GeneratedDockerfile::class)]
#[UsesClass(PhpProfile::class)]
#[UsesClass(JobImageNotBuilt::class)]
final class GeneratedDockerfileTest extends TestCase
{
    private string $bin = '';

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->bin);
    }

    public function testTheBaseImageIsTheExactPlatformVersion(): void
    {
        $dockerfile = GeneratedDockerfile::for(PhpProfile::fromComposerJson('{"require": {"php": ">=8.5.12"}, "config": {"platform": {"php": "8.5.12"}}}'));

        self::assertStringStartsWith('FROM php:8.5.12-cli'."\n", $dockerfile);
    }

    #[DataProvider('constraints')]
    public function testWithoutAPlatformVersionTheBaseIsTheNewestMinorTheConstraintAdmits(string $constraint, string $base): void
    {
        $dockerfile = GeneratedDockerfile::for(PhpProfile::fromComposerJson(sprintf('{"require": {"php": "%s"}}', $constraint)));

        self::assertStringStartsWith(sprintf('FROM php:%s-cli', $base)."\n", $dockerfile);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function constraints(): iterable
    {
        yield 'caret' => ['^8.3', '8.5'];
        yield 'tilde patch' => ['~8.3.4', '8.3'];
        yield 'range' => ['>=8.4.3 <8.5', '8.4'];
        yield 'alternatives' => ['8.2.* || 8.3.*', '8.3'];
    }

    public function testAConstraintNoSupportedMinorAdmitsIsRefused(): void
    {
        $this->expectExceptionObject(JobImageNotBuilt::noSupportedMinor('^7.4', ['8.5', '8.4', '8.3', '8.2']));

        GeneratedDockerfile::for(PhpProfile::fromComposerJson('{"require": {"php": "^7.4"}}'));
    }

    public function testComposerAndTheCliToolsArePinned(): void
    {
        $dockerfile = GeneratedDockerfile::for(PhpProfile::fromComposerJson('{"require": {"php": "^8.5"}}'));

        self::assertStringContainsString('COPY --from=composer:2.11.1@sha256:', $dockerfile);
        self::assertStringContainsString('@anthropic-ai/claude-code@2.1.322', $dockerfile);
        self::assertStringContainsString('@openai/codex@0.167.1', $dockerfile);
    }

    public function testWithoutExtensionsThereIsNoExtensionStep(): void
    {
        $dockerfile = GeneratedDockerfile::for(PhpProfile::fromComposerJson('{"require": {"php": "^8.5"}}'));

        self::assertStringNotContainsString('install-php-extensions "', $dockerfile);
    }

    public function testAnExtensionTheBaseImageLacksIsInstalled(): void
    {
        $step = $this->extensionStepFor('intl');
        $this->givenTheTools(
            loaded: 'ctype',
            installable: 'intl',
        );

        $process = $this->runTheStep($step);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringContainsString('installed intl', $process->getOutput());
    }

    public function testAnExtensionThatCannotBeInstalledFailsTheBuildWithItsName(): void
    {
        $step = $this->extensionStepFor('ctype', 'nosuchext');
        $this->givenTheTools(
            loaded: 'ctype',
            installable: 'intl',
        );

        $process = $this->runTheStep($step);

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('missing PHP extension: nosuchext', $process->getErrorOutput());
    }

    public function testAnExtensionTheBaseImageHasIsNotInstalledAgain(): void
    {
        $step = $this->extensionStepFor('zend-opcache');
        $this->givenTheTools(
            loaded: 'Zend OPcache',
            installable: 'none',
        );

        $process = $this->runTheStep($step);

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertStringNotContainsString('installed', $process->getOutput());
    }

    private function extensionStepFor(string ...$extensions): string
    {
        $require = ['php' => '^8.5'];
        foreach ($extensions as $extension) {
            $require['ext-'.$extension] = '*';
        }
        $dockerfile = GeneratedDockerfile::for(PhpProfile::fromComposerJson(json_encode(['require' => $require], JSON_THROW_ON_ERROR)));
        preg_match('/^RUN (set -eu; for extension in .*?done)$/ms', $dockerfile, $match);

        return $match[1] ?? self::fail('The generated Dockerfile has no extension step.');
    }

    // Stand-ins for php -m and the installer, so the step's shell runs here as it runs in the build.
    private function givenTheTools(string $loaded, string $installable): void
    {
        $this->bin = sys_get_temp_dir().'/intakt-image-tools-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->bin.'/loaded', "[PHP Modules]\n".$loaded."\n");
        $filesystem->dumpFile($this->bin.'/php', sprintf("#!/bin/sh\ncat '%s/loaded'\n", $this->bin));
        $filesystem->dumpFile($this->bin.'/install-php-extensions', sprintf("#!/bin/sh\n[ \"\$1\" = '%s' ] || { echo \"unsupported \$1\" >&2; exit 1; }\necho \"\$1\" >> '%s/loaded'\necho \"installed \$1\"\n", $installable, $this->bin));
        $filesystem->chmod([$this->bin.'/php', $this->bin.'/install-php-extensions'], 0o755);
    }

    private function runTheStep(string $step): Process
    {
        $process = new Process(['/bin/sh', '-c', $step], env: ['PATH' => $this->bin.':/usr/bin:/bin']);
        $process->run();

        return $process;
    }
}
