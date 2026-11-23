<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\HandBack;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// The script runs inside the install job's container, so it is tested by running it with sh -c as the container does.
#[CoversNothing]
final class InstallScriptTest extends TestCase
{
    private const string SCRIPT = __DIR__.'/../../../docker/job/install.sh';

    private const string PROBE = 'printf 8.5.12';

    private string $folder = '';

    public function testAnInstallThatSucceedsLeavesTheInstallWithoutTheLockFilesInTheKeptFolder(): void
    {
        $run = $this->whenTheInstallRuns(self::PROBE, 'mkdir -p vendor/composer && cp composer.lock vendor/composer/installed.json');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('install.log'));
        self::assertSame("installed\n", $this->outputFile('install-result'));
        self::assertSame('8.5.12', $this->outputFile('platform'));
        self::assertSame("{\"packages\": []}\n", (string) file_get_contents($this->folder.'/kept/vendor/composer/installed.json'));
        self::assertSame(['vendor'], array_values(array_diff((array) scandir($this->folder.'/kept'), ['.', '..'])));
    }

    public function testAnInstallTheLockGuardRefusesLeavesItsStatusAndKeepsNothing(): void
    {
        $run = $this->whenTheInstallRuns(self::PROBE, 'mkdir vendor && exit 78');

        self::assertSame(78, $run->getExitCode());
        self::assertSame("failed 78\n", $this->outputFile('install-result'));
        self::assertSame([], array_values(array_diff((array) scandir($this->folder.'/kept'), ['.', '..'])));
    }

    public function testAFolderTheInstallLeftWithoutPermissionsIsKeptOwnerWritable(): void
    {
        $run = $this->whenTheInstallRuns(self::PROBE, 'mkdir -p vendor/locked && touch vendor/locked/file && chmod 000 vendor/locked');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('install.log'));
        self::assertSame(0o700, fileperms($this->folder.'/kept/vendor/locked') & 0o700);
        self::assertFileExists($this->folder.'/kept/vendor/locked/file');
    }

    public function testAProbeThatFailsRunsNoInstall(): void
    {
        $run = $this->whenTheInstallRuns('exit 1', 'touch "$INTAKT_JOB_KEPT/ran"');

        self::assertSame(1, $run->getExitCode());
        self::assertSame("no-platform\n", $this->outputFile('install-result'));
        self::assertFileDoesNotExist($this->folder.'/kept/ran');
    }

    public function testTheInstallRunsOnACopyOfTheLockFilesAndNotOnTheMountedFolder(): void
    {
        $run = $this->whenTheInstallRuns(self::PROBE, 'test "$(pwd)" = "$TMPDIR/install" && test -f composer.lock && echo changed > composer.json && mkdir vendor');

        self::assertSame(0, $run->getExitCode(), $this->outputFile('install.log'));
        self::assertSame("{}\n", (string) file_get_contents($this->folder.'/lock/composer.json'));
    }

    public function testAnInstallThatLeavesNothingButTheLockFilesIsNotKept(): void
    {
        $run = $this->whenTheInstallRuns(self::PROBE, 'mkdir -p ../elsewhere/vendor');

        self::assertSame(1, $run->getExitCode());
        self::assertSame("not-kept\n", $this->outputFile('install-result'));
        self::assertSame([], array_values(array_diff((array) scandir($this->folder.'/kept'), ['.', '..'])));
    }

    public function testAnInstallWhoseKeptFolderCannotBeWrittenIsNotKept(): void
    {
        new Filesystem()->chmod($this->folder.'/kept', 0o500);

        $run = $this->whenTheInstallRuns(self::PROBE, 'mkdir vendor');

        self::assertSame(1, $run->getExitCode());
        self::assertSame("not-kept\n", $this->outputFile('install-result'));
    }

    public function testALockFileTheInstallCannotRemoveIsNotKept(): void
    {
        $run = $this->whenTheInstallRuns(self::PROBE, 'mkdir vendor && rm composer.json && mkdir composer.json');

        self::assertSame(1, $run->getExitCode());
        self::assertSame("not-kept\n", $this->outputFile('install-result'));
        self::assertSame([], array_values(array_diff((array) scandir($this->folder.'/kept'), ['.', '..'])));
    }

    public function testLockFilesThatCannotBeCopiedLeaveNotPreparedAndRunNoInstall(): void
    {
        new Filesystem()->remove($this->folder.'/lock');

        $run = $this->whenTheInstallRuns(self::PROBE, 'touch "$INTAKT_JOB_KEPT/ran"');

        self::assertSame(1, $run->getExitCode());
        self::assertSame("not-prepared\n", $this->outputFile('install-result'));
        self::assertFileDoesNotExist($this->folder.'/kept/ran');
    }

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-install-script-'.bin2hex(random_bytes(6));
        new Filesystem()->dumpFile($this->folder.'/lock/composer.json', "{}\n");
        new Filesystem()->dumpFile($this->folder.'/lock/composer.lock', "{\"packages\": []}\n");
        new Filesystem()->mkdir([$this->folder.'/output', $this->folder.'/kept', $this->folder.'/work']);
    }

    protected function tearDown(): void
    {
        new Process(['chmod', '-R', 'u+rwx', $this->folder])->run();
        new Filesystem()->remove($this->folder);
    }

    private function whenTheInstallRuns(string $probe, string $install): Process
    {
        $run = new Process(
            ['/bin/sh', '-c', (string) file_get_contents(self::SCRIPT), 'intakt-install', $this->folder.'/lock', $probe, $install, 'composer.json', 'composer.lock'],
            null,
            ['HOME' => $this->folder.'/work', 'TMPDIR' => $this->folder.'/work', 'INTAKT_JOB_OUTPUT' => $this->folder.'/output', 'INTAKT_JOB_KEPT' => $this->folder.'/kept'],
        );
        $run->run();

        return $run;
    }

    private function outputFile(string $name): string
    {
        return (string) file_get_contents($this->folder.'/output/'.$name);
    }
}
