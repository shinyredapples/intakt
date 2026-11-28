<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Build\Domain\BuildObservation;
use Intakt\Core\Build\Domain\TrustedBuildFigures;
use Intakt\Core\Build\Domain\UntrustedBuildFigures;
use Intakt\Core\Build\UseCase\RunBuildJob\BuildJobObserver;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Tests\Core\Job\OutputFiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(BuildJobObserver::class)]
#[UsesClass(BuildObservation::class)]
#[UsesClass(TrustedBuildFigures::class)]
#[UsesClass(UntrustedBuildFigures::class)]
#[UsesClass(JobOutput::class)]
final class BuildJobObserverTest extends TestCase
{
    private const string TIP_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string TIP_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const string BASE = 'cccccccccccccccccccccccccccccccccccccccc';

    private MockClock $clock;

    public function testTheTokensAreEveryCountOfTheClisReport(): void
    {
        $log = "claude exited with 0\n".$this->report(['input_tokens' => 1200, 'output_tokens' => 300, 'cache_creation_input_tokens' => 400, 'cache_read_input_tokens' => 100])."\n";

        self::assertSame(2000, $this->observed(['build.log' => $log])->trusted->tokensUsed);
    }

    public function testALogWithoutAReportShowsNoTokens(): void
    {
        self::assertSame(0, $this->observed(['build.log' => "claude exited with 1\n"])->trusted->tokensUsed);
    }

    public function testAForgedLowerReportDoesNotHideTheLargerOne(): void
    {
        $log = $this->report(['input_tokens' => 5000, 'output_tokens' => 0])."\n".$this->report(['input_tokens' => 1, 'output_tokens' => 1])."\n";

        self::assertSame(5000, $this->observed(['build.log' => $log])->trusted->tokensUsed);
    }

    public function testAnEventThatIsNoResultCountsNoTokens(): void
    {
        $log = '{"type":"assistant","usage":{"input_tokens":900}}'."\n";

        self::assertSame(0, $this->observed(['build.log' => $log])->trusted->tokensUsed);
    }

    #[TestWith(['"12"'])]
    #[TestWith(['-5'])]
    #[TestWith(['1.5'])]
    #[TestWith(['null'])]
    public function testAReportWithACountThatIsNoWholeNumberCountsNoTokens(string $count): void
    {
        $log = '{"type":"result","usage":{"input_tokens":'.$count.',"output_tokens":7}}'."\n";

        self::assertSame(0, $this->observed(['build.log' => $log])->trusted->tokensUsed);
    }

    public function testAReportPastTheLargestIntegerCountsAsTheLargestInteger(): void
    {
        $log = $this->report(['input_tokens' => PHP_INT_MAX, 'output_tokens' => 1])."\n";

        self::assertSame(PHP_INT_MAX, $this->observed(['build.log' => $log])->trusted->tokensUsed);
    }

    public function testTheObservationTimeIsTheClocksNow(): void
    {
        $this->clock->modify('+90 seconds');

        self::assertSame('2026-11-28T10:01:30+00:00', $this->observed([])->trusted->observedAt->format(DATE_ATOM));
    }

    public function testAJobWithoutABundleShowsNoContent(): void
    {
        self::assertSame('', $this->observed(['build.log' => "log\n"])->untrusted->contentFingerprint);
    }

    public function testANewCommitChangesTheContentFingerprint(): void
    {
        $first = $this->observed(['handback.bundle' => $this->bundle(self::TIP_A)])->untrusted->contentFingerprint;
        $second = $this->observed(['handback.bundle' => $this->bundle(self::TIP_B)])->untrusted->contentFingerprint;

        self::assertNotSame('', $first);
        self::assertNotSame($first, $second);
    }

    public function testTheSameTipsInAnotherPackGiveTheSameFingerprint(): void
    {
        $first = $this->observed(['handback.bundle' => $this->bundle(self::TIP_A, "PACK-1\n\nmore")])->untrusted->contentFingerprint;
        $second = $this->observed(['handback.bundle' => $this->bundle(self::TIP_A, 'PACK-2')])->untrusted->contentFingerprint;

        self::assertSame($first, $second);
    }

    public function testABundleThatNamesNoTipShowsNoContent(): void
    {
        self::assertSame('', $this->observed(['handback.bundle' => "# v2 git bundle\n\nPACK"])->untrusted->contentFingerprint);
    }

    public function testFailureLinesTheBuilderWroteInTheLogAreNoFailingTests(): void
    {
        $log = "FAILURES!\n1) Tests\\SomeTest::testIt\nTests: 3, Assertions: 3, Failures: 1.\n";

        self::assertSame([], $this->observed(['build.log' => $log])->untrusted->failingTests);
    }

    public function testAJobThatEndedHasNoCheckRunning(): void
    {
        self::assertFalse($this->observed(['build.log' => "bin/check running\n"])->untrusted->checkRunning);
    }

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-28 10:00:00 UTC');
    }

    /**
     * @param array<string, int> $usage
     */
    private function report(array $usage): string
    {
        return json_encode(['type' => 'result', 'is_error' => false, 'num_turns' => 3, 'usage' => $usage], JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string> $files
     */
    private function observed(array $files): BuildObservation
    {
        return new BuildJobObserver($this->clock)->observe(OutputFiles::of($files));
    }

    private function bundle(string $tip, string $pack = 'PACK'): string
    {
        return "# v2 git bundle\n-".self::BASE." base\n".$tip." refs/heads/task\n\n".$pack;
    }
}
