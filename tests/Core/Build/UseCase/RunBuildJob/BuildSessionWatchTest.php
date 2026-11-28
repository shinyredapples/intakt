<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Build\Contract\BuildBrief;
use Intakt\Core\Build\Contract\Decisions;
use Intakt\Core\Build\Domain\BuildLimits;
use Intakt\Core\Build\Domain\BuildProgress;
use Intakt\Core\Build\Domain\BuildStopCause;
use Intakt\Core\Build\Domain\BuildStopRule;
use Intakt\Core\Build\Domain\BuildWatch;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJob;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildLogEvents;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildLogReading;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildLogTally;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildSessionWatch;
use Intakt\Core\Build\UseCase\RunBuildJob\RecordedBuildStops;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobOutputPiece;
use Intakt\Tests\Core\Job\FollowedText;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

// Limits of allowance 100, idle 60 s, ceiling 500 and per-task total 1000 tokens, so a build at 50 tokens is under every limit and never idle-stopped.
#[CoversClass(BuildSessionWatch::class)]
#[UsesClass(BuildLogReading::class)]
#[UsesClass(BuildLogTally::class)]
#[UsesClass(BuildLogEvents::class)]
#[UsesClass(RecordedBuildStops::class)]
#[UsesClass(BuildStopRule::class)]
#[UsesClass(BuildWatch::class)]
#[UsesClass(BuildProgress::class)]
#[UsesClass(BuildLimits::class)]
#[UsesClass(BuildJob::class)]
#[UsesClass(BuildBrief::class)]
#[UsesClass(JobId::class)]
#[UsesClass(JobOutputPiece::class)]
final class BuildSessionWatchTest extends TestCase
{
    private MockClock $clock;

    private RecordedBuildStops $stops;

    private BuildSessionWatch $watch;

    public function testABuilderThatRemovesItsLogIsStoppedAtTheNextLookForTheRunawayCeiling(): void
    {
        self::assertFalse($this->looksAt(new FollowedText(BuildLogLines::usage('m1', 50))));

        self::assertTrue($this->looksAt(new FollowedText(null)));
        self::assertSame(BuildStopCause::RunawayCeiling, $this->stops->causeFor($this->job()));
    }

    public function testABuilderThatPutsAnotherFileInPlaceOfItsLogIsStopped(): void
    {
        $log = BuildLogLines::usage('m1', 50);
        self::assertFalse($this->looksAt(new FollowedText($log)));

        self::assertTrue($this->looksAt(new FollowedText($log.BuildLogLines::usage('m2', 1), 'another file')));
    }

    public function testABuilderThatShortensItsLogIsStopped(): void
    {
        self::assertFalse($this->looksAt(new FollowedText(BuildLogLines::usage('m1', 50).BuildLogLines::usage('m2', 10))));

        self::assertTrue($this->looksAt(new FollowedText(BuildLogLines::usage('m1', 50))));
    }

    public function testALogThatIsNotThereYetStopsNothing(): void
    {
        self::assertFalse($this->looksAt(new FollowedText(null)));
        self::assertFalse($this->looksAt(new FollowedText(BuildLogLines::usage('m1', 50))));
    }

    public function testALineSplitAcrossTwoLooksIsCountedOnceItEnds(): void
    {
        $line = BuildLogLines::usage('m1', 600);
        $half = substr($line, 0, 20);

        self::assertFalse($this->looksAt(new FollowedText($half)));
        self::assertTrue($this->looksAt(new FollowedText($line)));
        self::assertSame(BuildStopCause::RunawayCeiling, $this->stops->causeFor($this->job()));
    }

    public function testALineLongerThanTheBoundIsSkippedAndTheNextLineCounts(): void
    {
        $endless = '{"x":"'.str_repeat('a', BuildSessionWatch::LINE_BYTES + 1);

        self::assertFalse($this->looksAt(new FollowedText($endless)));
        self::assertTrue($this->looksAt(new FollowedText($endless."\"}\n".BuildLogLines::usage('m1', 600))));
    }

    public function testALineRightAtTheBoundIsKept(): void
    {
        $line = BuildLogLines::usage('m1', 600);
        $padded = substr($line, 0, -2).str_repeat(' ', BuildSessionWatch::LINE_BYTES - strlen($line) + 2);

        self::assertFalse($this->looksAt(new FollowedText($padded)));
        self::assertTrue($this->looksAt(new FollowedText($padded."}\n")));
    }

    public function testTheWatchFollowsTheBuildLog(): void
    {
        self::assertSame('build.log', $this->watch->followedFile());
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-01-01 00:00:00');
        $this->stops = new RecordedBuildStops();
        $this->watch = new BuildSessionWatch($this->job(), new BuildStopRule($this->clock), $this->stops, new BuildLimits(100, 60, 500, 1000));
    }

    private function looksAt(FollowedText $log): bool
    {
        $this->clock->sleep(BuildSessionWatch::INTERVAL_SECONDS);

        return $this->watch->endsSession($log);
    }

    private function job(): BuildJob
    {
        return new BuildJob('demo', 'clone', 'task', new BuildBrief('t', new Decisions(), null), Tier::Standard, 'img:1');
    }
}
