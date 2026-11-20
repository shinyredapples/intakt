<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

#[CoversClass(ScriptDirectory::class)]
#[UsesClass(CallRecords::class)]
#[UsesClass(MonotonicSeconds::class)]
final class ScriptDirectoryTest extends TestCase
{
    private string $directory = '';

    /** @var list<Process> */
    private array $processes = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-script-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->processes as $process) {
            $process->stop(0);
        }

        new Filesystem()->remove($this->directory);
    }

    /**
     * @param array<string,string> $environment
     */
    private function sleeper(array $environment): Process
    {
        // Without the variable unless named, because the test run itself could carry one.
        $process = new Process([PHP_BINARY, '-r', 'sleep(20);'], null, [ScriptDirectory::VARIABLE => false, ...$environment]);
        $process->start();
        $this->processes[] = $process;

        return $process;
    }

    public function testEachCallGetsItsOwnNumberAndRecordsItsStartAndEnd(): void
    {
        $script = new ScriptDirectory($this->directory);

        $first = $script->callStarted();
        $second = $script->callStarted();
        $script->callEnded($first);

        self::assertSame([1, 2], [$first, $second]);
        $calls = CallRecords::in($this->directory);
        self::assertSame([2, 1], [$calls->started(), $calls->ended()]);
        self::assertSame([getmypid()], $calls->processes());
    }

    public function testACallTakesTheNextNumberWhenAnotherWriterHoldsItsName(): void
    {
        mkdir($this->directory.'/calls');
        touch($this->directory.'/calls/1.start');

        $call = new ScriptDirectory($this->directory)->callStarted();

        self::assertSame(2, $call);
        self::assertSame('', file_get_contents($this->directory.'/calls/1.start'));
    }

    public function testEveryPublishedRecordIsCompleteAndLeavesNoOtherFile(): void
    {
        $script = new ScriptDirectory($this->directory);

        $script->callEnded($script->callStarted());

        self::assertSame(['1.end', '1.start'], array_values(array_diff((array) scandir($this->directory.'/calls'), ['.', '..'])));
        self::assertIsArray(json_decode((string) file_get_contents($this->directory.'/calls/1.start'), true));
        self::assertIsArray(json_decode((string) file_get_contents($this->directory.'/calls/1.end'), true));
    }

    public function testAnEndIsRecordedOnlyOnce(): void
    {
        $script = new ScriptDirectory($this->directory);
        $call = $script->callStarted();
        $script->callEnded($call);

        $this->expectException(RuntimeException::class);

        $script->callEnded($call);
    }

    public function testAProcessThatRunsWithTheScriptDirectoryIsItsWorker(): void
    {
        $process = $this->sleeper([ScriptDirectory::VARIABLE => $this->directory]);

        self::assertTrue(new ScriptDirectory($this->directory)->runsIn((int) $process->getPid()));
    }

    // A recorded process number the system has given to another process since: it must never be taken for a worker.
    public function testAProcessThatRunsWithoutTheScriptDirectoryIsNotItsWorker(): void
    {
        $process = $this->sleeper([]);

        self::assertFalse(new ScriptDirectory($this->directory)->runsIn((int) $process->getPid()));
    }

    public function testAProcessThatRunsWithAnotherScriptDirectoryNamedAlikeIsNotItsWorker(): void
    {
        $process = $this->sleeper([ScriptDirectory::VARIABLE => $this->directory.'-other']);

        self::assertFalse(new ScriptDirectory($this->directory)->runsIn((int) $process->getPid()));
    }

    public function testAProcessThatEndedIsNotItsWorker(): void
    {
        $process = new Process([PHP_BINARY, '-r', ''], null, [ScriptDirectory::VARIABLE => $this->directory]);
        $process->start();
        $pid = (int) $process->getPid();
        $process->wait();

        self::assertFalse(new ScriptDirectory($this->directory)->runsIn($pid));
    }

    public function testACallIsNotHeldWithoutAHoldFile(): void
    {
        $started = MonotonicSeconds::now();

        new ScriptDirectory($this->directory)->holdWhileScripted(1);

        self::assertLessThan(1.0, MonotonicSeconds::now() - $started);
    }

    public function testAHeldCallGoesOnOnceAllCallsAreReleased(): void
    {
        touch($this->directory.'/'.ScriptDirectory::HOLD);
        $script = new ScriptDirectory($this->directory);
        $script->releaseAll();
        $started = MonotonicSeconds::now();

        $script->holdWhileScripted(1);

        self::assertLessThan(1.0, MonotonicSeconds::now() - $started);
    }

    public function testAHeldCallGoesOnOnceItIsReleasedItself(): void
    {
        touch($this->directory.'/'.ScriptDirectory::HOLD);
        $script = new ScriptDirectory($this->directory);
        $call = $script->callStarted();
        $script->release($call);
        $started = MonotonicSeconds::now();

        $script->holdWhileScripted($call);

        self::assertLessThan(1.0, MonotonicSeconds::now() - $started);
    }

    public function testReleasingOneCallLeavesTheOthersHeld(): void
    {
        $script = new ScriptDirectory($this->directory);
        $first = $script->callStarted();
        $second = $script->callStarted();

        $script->release($first);

        self::assertSame([true, false], [$script->released($first), $script->released($second)]);
    }

    public function testACallWeighsNothingWithoutAWeightFile(): void
    {
        self::assertSame(0, new ScriptDirectory($this->directory)->weightInBytes());
    }

    public function testACallWeighsTheMegabytesTheWeightFileNames(): void
    {
        file_put_contents($this->directory.'/'.ScriptDirectory::WEIGHT, '3');

        self::assertSame(3 * 1024 * 1024, new ScriptDirectory($this->directory)->weightInBytes());
    }
}
