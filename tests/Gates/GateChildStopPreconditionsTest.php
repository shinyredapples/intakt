<?php

declare(strict_types=1);

namespace Intakt\Tests\Gates;

use Intakt\Gates\ProcessGroup;
use Intakt\Gates\ProcessResult;
use Intakt\Gates\ProcessRunner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversClass(ProcessGroup::class)]
#[CoversClass(ProcessRunner::class)]
#[UsesClass(ProcessResult::class)]
final class GateChildStopPreconditionsTest extends TestCase
{
    // Because open_basedir can only be narrowed and the fork must be the stopping process's own child, both happen in a PHP process of their own.
    private const string STOP_WITHOUT_PROC = <<<'PHP'
        require $argv[1];
        class_exists(Intakt\Gates\ProcessGroup::class);
        $pid = pcntl_fork();
        if (0 === $pid) {
            posix_kill(posix_getpid(), SIGSTOP);
            exit(99);
        }
        pcntl_waitpid($pid, $status, WUNTRACED);
        $narrow = static fn () => ini_set('open_basedir', dirname($argv[1], 2).':'.sys_get_temp_dir());
        if ('after' !== $argv[2]) {
            $narrow();
        }
        $group = Intakt\Gates\ProcessGroup::ofLauncher($pid);
        if ('after' === $argv[2]) {
            $narrow();
        }
        if (null === Intakt\Gates\ProcessGroup::whatTheStopLacks()) {
            throw new RuntimeException('/proc is still readable');
        }
        $group->stop();
        $ended = pcntl_waitpid($pid, $status, WNOHANG) === $pid;
        if (!$ended) {
            posix_kill($pid, SIGKILL);
            pcntl_waitpid($pid, $status);
        }
        echo $ended ? 'ended' : 'survived';
        PHP;

    private const string CONTAINED_RUN = <<<'PHP'
        require $argv[1];
        if ('' !== $argv[3]) {
            ini_set('open_basedir', dirname($argv[1], 2).':'.sys_get_temp_dir());
        }
        $result = new Intakt\Gates\ProcessRunner('/nonexistent/unshare')->runWithin(['touch', $argv[2]], sys_get_temp_dir(), 10);
        echo $result->exitCode, ' ', $result->errors;
        PHP;

    private string $folder;

    public function testALauncherWhoseStartTimeCannotBeReadIsStoppedWhileItIsStillBinCheckSChild(): void
    {
        $stop = new Process([PHP_BINARY, '-r', self::STOP_WITHOUT_PROC, $this->autoload(), 'before']);
        $stop->mustRun();

        self::assertSame('ended', $stop->getOutput());
    }

    public function testALauncherHeldBeforeItsSetsidIsStoppedWhenProcBecomesUnreadableAfterItsStart(): void
    {
        $stop = new Process([PHP_BINARY, '-r', self::STOP_WITHOUT_PROC, $this->autoload(), 'after']);
        $stop->mustRun();

        self::assertSame('ended', $stop->getOutput());
    }

    public function testAContainedRunRefusesToStartInAPhpProcessWhoseStopCannotAskWhetherTheLauncherIsStillItsChild(): void
    {
        $run = new Process([PHP_BINARY, '-d', 'disable_functions=pcntl_waitid', '-r', self::CONTAINED_RUN, $this->autoload(), $this->folder.'/marker', '']);
        $run->mustRun();

        self::assertSame(['127 cannot run touch: the PHP function pcntl_waitid is missing', false], [$run->getOutput(), is_file($this->folder.'/marker')]);
    }

    public function testAContainedRunRefusesToStartInAPhpProcessThatCannotReadProc(): void
    {
        $run = new Process([PHP_BINARY, '-r', self::CONTAINED_RUN, $this->autoload(), $this->folder.'/marker', 'narrowed']);
        $run->mustRun();

        self::assertMatchesRegularExpression('#^127 cannot run touch: the stop cannot read /proc/\d+/stat$#', $run->getOutput());
        self::assertFileDoesNotExist($this->folder.'/marker');
    }

    public function testAContainedRunStartsInAPhpProcessThatHasAllTheStopNeeds(): void
    {
        $run = new Process([PHP_BINARY, '-r', self::CONTAINED_RUN, $this->autoload(), $this->folder.'/marker', '']);
        $run->mustRun();

        self::assertSame(['0 ', true], [$run->getOutput(), is_file($this->folder.'/marker')]);
    }

    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir().'/intakt-stop-preconditions-'.bin2hex(random_bytes(6));
        mkdir($this->folder, 0o700, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    private function autoload(): string
    {
        return dirname(__DIR__, 2).'/vendor/autoload.php';
    }
}
