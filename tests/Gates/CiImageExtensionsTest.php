<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

// A fixed list before the copy lets a composer.json edit reuse the expensive layer; the check after it keeps the list.
#[CoversNothing]
final class CiImageExtensionsTest extends TestCase
{
    private const string DOCKERFILE = __DIR__.'/../../.github/ci/Dockerfile';

    public function testTheExtensionsAreInstalledBeforeComposerJsonIsCopied(): void
    {
        $dockerfile = (string) file_get_contents(self::DOCKERFILE);
        $install = strpos($dockerfile, 'docker-php-ext-install');
        $copy = strpos($dockerfile, 'COPY composer.json');

        self::assertIsInt($install);
        self::assertIsInt($copy);
        self::assertLessThan($copy, $install);
    }

    public function testARequiredExtensionThatIsNotLoadedFailsTheBuildNamingIt(): void
    {
        $process = $this->check('{"require": {"php": ">=8.5", "ext-ctype": "*", "ext-intakt_absent": "*"}}');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('ext-intakt_absent', $process->getErrorOutput());
        self::assertStringNotContainsString('ext-ctype', $process->getErrorOutput());
    }

    public function testRequiredExtensionsThatAreAllLoadedPass(): void
    {
        $process = $this->check('{"require": {"php": ">=8.5", "ext-ctype": "*"}}');

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    private function check(string $composer): Process
    {
        if (1 !== preg_match("/^RUN php( -r '.*composer\\.json.*')$/m", (string) file_get_contents(self::DOCKERFILE), $run)) {
            self::fail('.github/ci/Dockerfile has no RUN php -r line that checks composer.json.');
        }

        $process = Process::fromShellCommandline(PHP_BINARY.str_replace('/usr/local/share/intakt/composer.json', 'php://stdin', $run[1]), input: $composer);
        $process->run();

        return $process;
    }
}
