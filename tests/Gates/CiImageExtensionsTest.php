<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

#[CoversNothing]
final class CiImageExtensionsTest extends TestCase
{
    public function testTheExtensionsAreInstalledBeforeComposerJsonIsCopied(): void
    {
        $dockerfile = $this->dockerfile();

        self::assertLessThan((int) strpos($dockerfile, 'COPY composer.json'), (int) strpos($dockerfile, 'docker-php-ext-install'));
    }

    public function testAMissingExtensionFailsTheBuildNamingIt(): void
    {
        $process = $this->check('{"require": {"ext-ctype": "*", "ext-intakt_absent": "*"}}');

        self::assertSame(1, $process->getExitCode());
        self::assertSame("missing PHP extension intakt_absent\n", $process->getErrorOutput());
    }

    public function testLoadedExtensionsPass(): void
    {
        self::assertSame(0, $this->check('{"require": {"ext-ctype": "*"}}')->getExitCode());
    }

    public function testAComposerJsonThatDoesNotParseFailsTheBuild(): void
    {
        self::assertNotSame(0, $this->check('{"require": {')->getExitCode());
    }

    private function check(string $composer): Process
    {
        self::assertSame(1, preg_match('/^RUN (set -eu; \\\\\n\s+missing=.*\n\s+test -z "\$left")$/ms', $this->dockerfile(), $run));
        $process = Process::fromShellCommandline(str_replace('/usr/local/share/intakt/composer.json', 'php://stdin', $run[1] ?? ''), input: $composer);
        $process->run();

        return $process;
    }

    private function dockerfile(): string
    {
        return (string) file_get_contents(__DIR__.'/../../.github/ci/Dockerfile');
    }
}
