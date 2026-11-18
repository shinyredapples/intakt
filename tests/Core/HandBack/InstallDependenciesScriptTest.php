<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// The script runs inside the install job's container, so it is tested by running it with sh -c as the container does.
#[CoversNothing]
final class InstallDependenciesScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../docker/job/install-dependencies.sh';

    private string $folder = '';

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-install-'.bin2hex(random_bytes(6));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->folder.'/lock/composer.lock', "{\"packages\": []}\n");
        $filesystem->dumpFile($this->folder.'/lock/composer.json', "{}\n");
        $filesystem->mkdir([$this->folder.'/kept', $this->folder.'/output', $this->folder.'/work']);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    public function testTheInstalledFolderIsKeptAndTheInstallSaysSo(): void
    {
        $run = $this->whenTheInstallRuns('test -f composer.lock && test -f composer.json && mkdir -p vendor/acme && echo lib > vendor/acme/lib.txt');

        self::assertSame(0, $run->getExitCode(), $run->getErrorOutput());
        self::assertSame("lib\n", file_get_contents($this->folder.'/kept/vendor/acme/lib.txt'));
        self::assertSame("installed\n", file_get_contents($this->folder.'/output/install-result'));
    }

    public function testAFailedInstallKeepsNothingAndLeavesNoResult(): void
    {
        $run = $this->whenTheInstallRuns('mkdir -p vendor/acme && echo network timeout && exit 1');

        self::assertSame(1, $run->getExitCode());
        self::assertDirectoryDoesNotExist($this->folder.'/kept/vendor');
        self::assertFileDoesNotExist($this->folder.'/output/install-result');
        self::assertSame("network timeout\n", file_get_contents($this->folder.'/output/install.log'));
    }

    public function testAnInstallThatMadeNoFolderLeavesNoResult(): void
    {
        $run = $this->whenTheInstallRuns('true');

        self::assertSame(1, $run->getExitCode());
        self::assertFileDoesNotExist($this->folder.'/output/install-result');
    }

    private function whenTheInstallRuns(string $command): Process
    {
        $run = new Process(
            ['/bin/sh', '-c', (string) file_get_contents(self::SCRIPT), 'intakt-install-dependencies', $this->folder.'/lock', $this->folder.'/kept', 'vendor', '--', $command],
            null,
            ['HOME' => $this->folder.'/work', 'TMPDIR' => $this->folder.'/work', 'INTAKT_JOB_OUTPUT' => $this->folder.'/output'],
        );
        $run->run();

        return $run;
    }
}
