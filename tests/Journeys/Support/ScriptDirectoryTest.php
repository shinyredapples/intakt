<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ScriptDirectory::class)]
#[UsesClass(CallRecords::class)]
#[UsesClass(MonotonicSeconds::class)]
#[UsesClass(RecordedProcess::class)]
final class ScriptDirectoryTest extends TestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-script-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0o777, true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
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
        self::assertEquals([RecordedProcess::current()], $calls->workers());
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

    // A file size limit of one byte, because it cuts the record's write short in this process the way a full disk would, and a soft limit can be raised again afterwards.
    public function testARecordWrittenOnlyInPartIsNeverPublished(): void
    {
        $script = new ScriptDirectory($this->directory);
        $script->callEnded($script->callStarted());
        $limits = posix_getrlimit();
        self::assertIsArray($limits);
        [$soft, $hard] = [$limits['soft filesize'] ?? 'unlimited', $limits['hard filesize'] ?? 'unlimited'];
        pcntl_signal(SIGXFSZ, SIG_IGN);
        posix_setrlimit(POSIX_RLIMIT_FSIZE, 1, 'unlimited' === $hard ? POSIX_RLIMIT_INFINITY : (int) $hard);
        set_error_handler(static fn (): bool => true);

        try {
            $script->callStarted();
            self::fail('A record written only in part was published.');
        } catch (RuntimeException) {
        } finally {
            restore_error_handler();
            posix_setrlimit(POSIX_RLIMIT_FSIZE, 'unlimited' === $soft ? POSIX_RLIMIT_INFINITY : (int) $soft, 'unlimited' === $hard ? POSIX_RLIMIT_INFINITY : (int) $hard);
            pcntl_signal(SIGXFSZ, SIG_DFL);
        }

        self::assertSame(['1.end', '1.start'], array_values(array_diff((array) scandir($this->directory.'/calls'), ['.', '..'])));
    }

    public function testAnEndIsRecordedOnlyOnce(): void
    {
        $script = new ScriptDirectory($this->directory);
        $call = $script->callStarted();
        $script->callEnded($call);

        $this->expectException(RuntimeException::class);

        $script->callEnded($call);
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
