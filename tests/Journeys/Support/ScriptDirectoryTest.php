<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;

#[CoversClass(ScriptDirectory::class)]
#[UsesClass(ScriptedCall::class)]
#[UsesClass(ScriptNotArmed::class)]
#[UsesClass(CallEndedTwice::class)]
final class ScriptDirectoryTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = new Filesystem()->tempnam(sys_get_temp_dir(), 'scripts-');
        new Filesystem()->remove($this->path);
        new Filesystem()->mkdir($this->path);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->path);
    }

    public function testEachStartedCallGetsTheNextFreeNumber(): void
    {
        $scripts = $this->scripts();

        self::assertSame([1, 2], [$scripts->callStarted(), $scripts->callStarted()]);
    }

    public function testACallSkipsANumberWhoseStartFileAppearedMeanwhile(): void
    {
        $scripts = $this->scripts();
        $scripts->callStarted();
        file_put_contents($this->path.'/3.start', '');
        new Filesystem()->remove($this->path.'/1.start');

        self::assertSame(2, $scripts->callStarted());
        self::assertSame(4, $scripts->callStarted());
    }

    public function testAnEmptyStartFileLeftByAnotherWriterIsSkippedByTheReader(): void
    {
        file_put_contents($this->path.'/1.start', '');
        $scripts = $this->scripts();

        $call = $scripts->callStarted();

        self::assertEquals([new ScriptedCall(2, (int) getmypid(), 1000.0, null)], $scripts->calls());
        self::assertSame(2, $call);
    }

    public function testAPartialStartRecordIsSkippedByTheReader(): void
    {
        file_put_contents($this->path.'/1.start', '{"call":1,"pi');
        $scripts = $this->scripts();
        $scripts->callStarted();

        self::assertSame([2], array_map(static fn (ScriptedCall $call): int => $call->number, $scripts->calls()));
    }

    public function testAnEndRecordIsReadBackWithItsCall(): void
    {
        $clock = new MockClock('@1000');
        $scripts = new ScriptDirectory($this->path, $clock);
        $call = $scripts->callStarted();
        $clock->sleep(2.5);

        $scripts->callEnded($call);

        self::assertEquals([new ScriptedCall(1, (int) getmypid(), 1000.0, 1002.5)], $scripts->calls());
    }

    public function testACallCannotEndTwice(): void
    {
        $scripts = $this->scripts();
        $call = $scripts->callStarted();
        $scripts->callEnded($call);

        $this->expectException(CallEndedTwice::class);

        $scripts->callEnded($call);
    }

    public function testNoTemporaryFileRemainsAfterARecordIsPublished(): void
    {
        $scripts = $this->scripts();
        $scripts->callEnded($scripts->callStarted());

        self::assertSame(['1.end', '1.start'], array_values(array_diff(scandir($this->path) ?: [], ['.', '..'])));
    }

    public function testAnArmedScriptIsReadBack(): void
    {
        $scripts = $this->scripts();

        $scripts->arm(1.5, [['content_type' => 'application/json', 'body' => '{}']]);

        self::assertSame(['weight' => 1.5, 'answers' => [['content_type' => 'application/json', 'body' => '{}']]], $scripts->script());
    }

    public function testAScriptWithoutAnswersIsRefused(): void
    {
        file_put_contents($this->path.'/script.json', '{"weight":1,"answers":[]}');

        $this->expectException(ScriptNotArmed::class);

        $this->scripts()->script();
    }

    public function testAScriptWithANegativeWeightIsRefused(): void
    {
        file_put_contents($this->path.'/script.json', '{"weight":-1,"answers":[{"content_type":"text/plain","body":""}]}');

        $this->expectException(ScriptNotArmed::class);

        $this->scripts()->script();
    }

    public function testAMissingScriptIsRefused(): void
    {
        $this->expectException(ScriptNotArmed::class);

        $this->scripts()->script();
    }

    private function scripts(): ScriptDirectory
    {
        return new ScriptDirectory($this->path, new MockClock('@1000'));
    }
}
