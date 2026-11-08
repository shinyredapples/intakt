<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Dashboard\Infrastructure;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// A stand-in php on the PATH reports the version, because the floor check must judge the running PHP, not the image tag.
#[CoversNothing]
final class PhpFloorCheckTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-floor-'.bin2hex(random_bytes(4));
        (new Filesystem())->mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
    }

    public function testARunningPhp8511FailsTheFloorCheck(): void
    {
        $process = $this->check('8.5.11');

        self::assertSame(1, $process->getExitCode());
        self::assertStringContainsString('PHP 8.5.12 or later is required', $process->getErrorOutput());
    }

    public function testARunningPhp8512PassesTheFloorCheck(): void
    {
        self::assertSame(0, $this->check('8.5.12')->getExitCode());
    }

    public function testARunningPhp8600PassesTheFloorCheck(): void
    {
        self::assertSame(0, $this->check('8.6.0')->getExitCode());
    }

    private function check(string $version): Process
    {
        file_put_contents($this->directory.'/php', "#!/bin/bash\nphp_version=".$version."\n".<<<'BASH'
            [[ $1 == -r ]] || exit 2
            echo "$php_version"
            [[ $(printf '%s\n8.5.12\n' "$php_version" | sort -V | head -1) == 8.5.12 ]]
            BASH);
        chmod($this->directory.'/php', 0o755);

        $process = new Process([__DIR__.'/../../../../docker/check-php-floor.sh'], null, ['PATH' => $this->directory.':'.getenv('PATH')]);
        $process->run();

        return $process;
    }
}
