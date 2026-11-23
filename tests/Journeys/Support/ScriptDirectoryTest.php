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
#[UsesClass(RecordNotPublished::class)]
#[UsesClass(ProcParents::class)]
#[UsesClass(ProcessEntry::class)]
final class ScriptDirectoryTest extends TestCase
{
    private string $path;

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

        self::assertEquals([new ScriptedCall(2, (int) getmypid(), $this->ownStart(), 1000.0, null)], $scripts->calls());
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

        self::assertEquals([new ScriptedCall(1, (int) getmypid(), $this->ownStart(), 1000.0, 1002.5)], $scripts->calls());
    }

    public function testACallCannotEndTwice(): void
    {
        $scripts = $this->scripts();
        $call = $scripts->callStarted();
        $scripts->callEnded($call);

        $this->expectException(CallEndedTwice::class);

        $scripts->callEnded($call);
    }

    public function testANameTakenByALinkToNothingIsSkippedLikeAnyTakenName(): void
    {
        new Filesystem()->symlink($this->path.'/nothing', $this->path.'/1.start');

        self::assertSame(2, $this->scripts()->callStarted());
    }

    public function testALinkFailureForAFreeNameFailsTheCallInsteadOfTryingTheNextNumber(): void
    {
        $scripts = new ScriptDirectory($this->path, new MockClock('@1000'), static fn (string $temporary, string $file): bool => false);

        $this->expectException(RecordNotPublished::class);

        $scripts->callStarted();
    }

    public function testALinkFailureForAFreeEndNameFailsTheEndInsteadOfCountingAsASecondEnd(): void
    {
        $scripts = new ScriptDirectory($this->path, new MockClock('@1000'), static fn (string $temporary, string $file): bool => str_ends_with($file, '.start') && link($temporary, $file));
        $call = $scripts->callStarted();

        $this->expectException(RecordNotPublished::class);

        $scripts->callEnded($call);
    }

    public function testALinkThatLandsBehindALinkPutAtTheNameAfterTheScanDoesNotClaimTheNumber(): void
    {
        $path = $this->path;
        $throughALink = static function (string $temporary, string $file) use ($path): bool {
            symlink($path.'/elsewhere', $file);

            return link($temporary, $path.'/elsewhere');
        };
        $scripts = new ScriptDirectory($this->path, new MockClock('@1000'), $throughALink);

        $this->expectException(RecordNotPublished::class);

        $scripts->callStarted();
    }

    public function testARecordRejectedBecauseItLandedBehindALinkIsNotReadBack(): void
    {
        $path = $this->path;
        $throughALinkToTheNextName = static function (string $temporary, string $file) use ($path): bool {
            symlink($path.'/2.start', $file);

            return link($temporary, $path.'/2.start');
        };
        $scripts = new ScriptDirectory($this->path, new MockClock('@1000'), $throughALinkToTheNextName);

        try {
            $scripts->callStarted();
            self::fail('The publication was expected to be rejected.');
        } catch (RecordNotPublished) {
            self::assertSame([], $scripts->calls());
        }
    }

    public function testARecordRejectedBecauseItLandedBehindALinkLeavesNoFileWhereItLanded(): void
    {
        $path = $this->path;
        $throughALink = static function (string $temporary, string $file) use ($path): bool {
            symlink($path.'/elsewhere', $file);

            return link($temporary, $path.'/elsewhere');
        };

        try {
            new ScriptDirectory($this->path, new MockClock('@1000'), $throughALink)->callStarted();
            self::fail('The publication was expected to be rejected.');
        } catch (RecordNotPublished) {
            self::assertFileDoesNotExist($this->path.'/elsewhere');
        }
    }

    public function testALinkAtAStartNameToTheNextNameDoesNotShowTheNextCallTwice(): void
    {
        symlink($this->path.'/2.start', $this->path.'/1.start');
        $scripts = $this->scripts();

        $call = $scripts->callStarted();

        self::assertSame([$call], array_map(static fn (ScriptedCall $started): int => $started->number, $scripts->calls()));
    }

    public function testAStartRecordUnderTheNameOfAnotherCallIsSkippedByTheReader(): void
    {
        $scripts = $this->scripts();
        $call = $scripts->callStarted();
        link($this->path.'/'.$call.'.start', $this->path.'/7.start');

        self::assertSame([$call], array_map(static fn (ScriptedCall $started): int => $started->number, $scripts->calls()));
    }

    public function testAZeroPaddedLinkAndCopyBesideAPublishedStartDoNotListTheCallTwice(): void
    {
        $scripts = $this->scripts();
        $call = $scripts->callStarted();
        link($this->path.'/'.$call.'.start', $this->path.'/0'.$call.'.start');
        copy($this->path.'/'.$call.'.start', $this->path.'/00'.$call.'.start');

        self::assertSame([$call], array_map(static fn (ScriptedCall $started): int => $started->number, $scripts->calls()));
    }

    public function testNoTemporaryFileRemainsAfterARecordIsPublished(): void
    {
        $scripts = $this->scripts();
        $scripts->callEnded($scripts->callStarted());

        self::assertSame(['1.end', '1.start'], array_values(array_diff((array) scandir($this->path), ['.', '..'])));
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

    private function scripts(): ScriptDirectory
    {
        return new ScriptDirectory($this->path, new MockClock('@1000'));
    }

    private function ownStart(): int
    {
        return (int) new ProcParents()->startOf((int) getmypid());
    }
}
