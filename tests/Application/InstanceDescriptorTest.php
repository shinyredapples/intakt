<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

#[CoversNothing]
#[Group('instance')]
final class InstanceDescriptorTest extends InstanceTestCase
{
    public function testWithoutDescriptorThreeNoStatusIsWrittenAndTheExitIsNonZero(): void
    {
        $process = $this->runInstanceWithoutDescriptorThree([]);

        self::assertSame(1, $process->getExitCode());
        self::assertStringNotContainsString('"status"', $process->getOutput());
    }

    public function testADescriptorThreeThatOpcacheOpenedForItsLockCountsAsMissing(): void
    {
        if (!extension_loaded('Zend OPcache')) {
            self::markTestSkipped('The Zend OPcache extension is not loaded, so nothing of PHP takes descriptor 3.');
        }

        $process = $this->runInstanceWithoutDescriptorThree(['-d', 'opcache.enable=1', '-d', 'opcache.enable_cli=1']);

        self::assertSame(1, $process->getExitCode());
        self::assertStringNotContainsString('"status"', $process->getOutput());
    }

    public function testAMemfdWhoseNameOnlyStartsLikeOpcachesLockReceivesTheStatusLine(): void
    {
        $process = $this->runInstanceWithAMemfdNamed('opcache_lock_status');

        self::assertSame('{"status":"ready"}', trim($process->getErrorOutput()));
        self::assertSame(0, $process->getExitCode());
    }

    public function testAPipeAsDescriptorThreeReceivesTheStatusLine(): void
    {
        $shell = 'exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::ROOT.'/bin/instance').' 3>&1 >/dev/null';
        $process = Process::fromShellCommandline($shell, null, $this->environment());
        $process->run();

        self::assertSame('{"status":"ready"}', trim($process->getOutput()));
        self::assertSame(0, $process->getExitCode());
    }

    public function testTheServerNeverHoldsTheStatusDescriptor(): void
    {
        $statusFile = $this->scratch.'/status';
        $caller = $this->callerHoldingManyDescriptors($statusFile);

        $caller->start();
        $held = $this->descriptorsOfTheServerWhile($caller);

        self::assertNotNull($held, 'the server was never seen running');
        self::assertNotContains(realpath($statusFile), $held);
        self::assertSame('{"status":"ready"}', trim((string) file_get_contents($statusFile)));
    }

    public function testTheScratchFolderIsCreatedWhenTheFirstAttemptLosesTheRaceWithARmdir(): void
    {
        // The first mkdir must fail as if var/ had just been removed, because a real race cannot be forced; later calls reach the real file system.
        $prepend = $this->scratch.'/lose_first_mkdir.php';
        file_put_contents($prepend, <<<'PHP'
            <?php
            final class LoseFirstMkdir
            {
                public $context;

                public function url_stat(string $path, int $flags): array|false
                {
                    stream_wrapper_restore('file');
                    $result = @stat($path);
                    stream_wrapper_unregister('file');
                    stream_wrapper_register('file', self::class);

                    return $result;
                }

                public function mkdir(string $path, int $mode, int $options): bool
                {
                    stream_wrapper_restore('file');
                    trigger_error('mkdir(): No such file or directory', E_USER_WARNING);

                    return false;
                }
            }
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', LoseFirstMkdir::class);
            PHP);

        $run = $this->runInstanceWithPhpOptions(['-d', 'auto_prepend_file='.$prepend]);

        self::assertSame('{"status":"ready"}', $run['status']);
        self::assertSame(0, $run['exit']);
    }

    public function testTheMessageNamesAnUnknownReasonWhenMkdirFailsWithoutAWarning(): void
    {
        $prepend = $this->scratch.'/silent_mkdir_failure.php';
        file_put_contents($prepend, <<<'PHP'
            <?php
            final class SilentMkdirFailure
            {
                public $context;

                public function url_stat(string $path, int $flags): array|false
                {
                    stream_wrapper_restore('file');
                    $result = @stat($path);
                    stream_wrapper_unregister('file');
                    stream_wrapper_register('file', self::class);

                    return $result;
                }

                public function mkdir(string $path, int $mode, int $options): bool
                {
                    return false;
                }
            }
            stream_wrapper_unregister('file');
            stream_wrapper_register('file', SilentMkdirFailure::class);
            PHP);

        $run = $this->runInstanceWithPhpOptions(['-d', 'auto_prepend_file='.$prepend]);

        $status = $this->decoded($run['status']);
        self::assertSame('failed', $status['status']);
        self::assertSame('cannot create the scratch directory: unknown reason', $status['reason']);
    }

    /**
     * @param list<string> $phpOptions
     *
     * @return array{status: string, exit: int}
     */
    private function runInstanceWithPhpOptions(array $phpOptions): array
    {
        $statusFile = $this->scratch.'/status';
        $shell = 'exec '.implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, ...$phpOptions, self::ROOT.'/bin/instance'])).' 3>'.escapeshellarg($statusFile);
        $process = Process::fromShellCommandline($shell, null, $this->environment());
        $process->run();

        return ['status' => trim((string) file_get_contents($statusFile)), 'exit' => (int) $process->getExitCode()];
    }

    /**
     * @param list<string> $phpOptions
     */
    private function runInstanceWithoutDescriptorThree(array $phpOptions): Process
    {
        // PHPUnit's own descriptor 3 (Composer's bin proxy keeps the script open) would otherwise reach the child as a read-only file.
        $shell = 'exec '.implode(' ', array_map(escapeshellarg(...), [PHP_BINARY, ...$phpOptions, self::ROOT.'/bin/instance'])).' 3>&-';
        $process = Process::fromShellCommandline($shell, null, ['TMPDIR' => $this->scratch.'/tmp', 'PATH' => $this->pathWithoutFrankenphp()]);
        $process->run();

        return $process;
    }

    private function runInstanceWithAMemfdNamed(string $name): Process
    {
        $python = new ExecutableFinder()->find('python3');
        if (null === $python) {
            self::markTestSkipped('python3 is not on the PATH, and the memfd helper needs it.');
        }

        $helper = 'import os, subprocess, sys; fd = os.memfd_create(sys.argv[1]); os.dup2(fd, 3); '
            .'code = subprocess.run(sys.argv[2:], pass_fds=(3,)).returncode; os.lseek(3, 0, 0); '
            .'sys.stderr.write(os.read(3, 4096).decode()); sys.exit(code)';
        $process = new Process([$python, '-I', '-c', $helper, $name, PHP_BINARY, self::ROOT.'/bin/instance'], null, $this->environment());
        $process->run();

        return $process;
    }

    // Regression guard: the caller holds descriptors 3 to 14 so a duplicate of descriptor 3 above 9 would show up, which a shell redirection cannot name.
    private function callerHoldingManyDescriptors(string $statusFile): Process
    {
        $command = 'exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(self::ROOT.'/bin/instance').' 3>'.escapeshellarg($statusFile);
        $caller = 'for ($i = 0; $i < 12; ++$i) { $held[] = fopen("/dev/null", "r"); } passthru('.var_export($command, true).');';

        return new Process([PHP_BINARY, '-r', $caller], null, $this->environment());
    }

    /**
     * @return list<string>|null
     */
    private function descriptorsOfTheServerWhile(Process $caller): ?array
    {
        $held = null;

        while ($caller->isRunning()) {
            foreach ($this->serverProcesses() as $directory) {
                $held = [...$held ?? [], ...$this->descriptorTargets($directory)];
            }

            usleep(20_000);
        }

        return $held;
    }

    /**
     * @return list<string>
     */
    private function serverProcesses(): array
    {
        $public = realpath(self::ROOT).'/public';

        return array_values(array_filter(
            $this->matching('/proc/[0-9]*'),
            static function (string $directory) use ($public): bool {
                $arguments = explode("\0", (string) @file_get_contents($directory.'/cmdline'));

                return PHP_BINARY === $arguments[0] && in_array('-S', $arguments, true) && in_array($public, $arguments, true);
            },
        ));
    }

    /**
     * @return list<string>
     */
    private function descriptorTargets(string $process): array
    {
        $targets = [];

        foreach ($this->matching($process.'/fd/*') as $descriptor) {
            $target = @readlink($descriptor);
            $targets[] = false === $target ? '' : $target;
        }

        return $targets;
    }
}
