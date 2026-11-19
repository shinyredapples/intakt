<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ScriptDirectory::class)]
#[UsesClass(CallRecords::class)]
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
    }

    public function testACallIsNotHeldWithoutAHoldFile(): void
    {
        $started = microtime(true);

        new ScriptDirectory($this->directory)->holdWhileScripted(1);

        self::assertLessThan(1.0, microtime(true) - $started);
    }

    public function testAHeldCallGoesOnOnceAllCallsAreReleased(): void
    {
        touch($this->directory.'/'.ScriptDirectory::HOLD);
        $script = new ScriptDirectory($this->directory);
        $script->releaseAll();
        $started = microtime(true);

        $script->holdWhileScripted(1);

        self::assertLessThan(1.0, microtime(true) - $started);
    }

    public function testAHeldCallGoesOnOnceItIsReleasedItself(): void
    {
        touch($this->directory.'/'.ScriptDirectory::HOLD);
        $script = new ScriptDirectory($this->directory);
        $call = $script->callStarted();
        $script->release($call);
        $started = microtime(true);

        $script->holdWhileScripted($call);

        self::assertLessThan(1.0, microtime(true) - $started);
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
