<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Bench\Domain;

use Intakt\Core\Bench\Domain\ChangeFiles;
use Intakt\Core\Bench\Domain\DefectMatcher;
use Intakt\Core\Bench\Domain\Expectations;
use Intakt\Core\Bench\Domain\FailureCause;
use Intakt\Core\Bench\Domain\PlantedDefect;
use Intakt\Core\Bench\Domain\RunClassifier;
use Intakt\Core\Bench\Domain\RunOutcome;
use Intakt\Core\Bench\Domain\RunVerdict;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Tests\Core\Review\Contract\ScriptedReviewer;
use Intakt\Tests\Core\Review\Contract\VerdictDocument;
use Intakt\Tests\Support\KernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(RunClassifier::class)]
#[CoversClass(RunOutcome::class)]
#[CoversClass(RunVerdict::class)]
#[CoversClass(ChangeFiles::class)]
#[CoversClass(FailureCause::class)]
#[UsesClass(DefectMatcher::class)]
#[UsesClass(Expectations::class)]
#[UsesClass(PlantedDefect::class)]
final class RunClassifierTest extends KernelTestCase
{
    private const string DEFECTS = '[{"id":"d1","file":"src/a.js","lines":{"from":4,"to":6},"description":"Wrong.","severity":"P1"}]';

    public function testAFindingInTheRangeOfAScriptedReviewCatchesTheDefect(): void
    {
        $findings = $this->scriptedFindings([['src/a.js', 5]]);

        $verdict = $this->classify(self::DEFECTS, RunOutcome::completed($findings));

        self::assertSame(['d1'], $verdict->caught);
        self::assertSame([], $verdict->missed);
        self::assertSame([], $verdict->unmatched);
    }

    public function testAnUnmatchedFindingOnACleanChangeCountsAsAFalseAlarm(): void
    {
        $findings = $this->scriptedFindings([['src/a.js', 2]]);

        $verdict = $this->classify('[]', RunOutcome::completed($findings));

        self::assertSame($findings, $verdict->falseAlarms);
        self::assertSame([], $verdict->unmatched);
    }

    public function testAnUnmatchedFindingOnAChangeWithDefectsIsReportedButNotCounted(): void
    {
        $findings = $this->scriptedFindings([['src/a.js', 5], ['src/other.js', 1]]);

        $verdict = $this->classify(self::DEFECTS, RunOutcome::completed($findings));

        self::assertSame([], $verdict->falseAlarms);
        self::assertCount(1, $verdict->unmatched);
        self::assertSame('src/other.js', $verdict->unmatched[0]->file);
    }

    public function testAMissedDefectInARunThatCompletedIsTheReviewersMiss(): void
    {
        $verdict = $this->classify(self::DEFECTS, RunOutcome::completed([]));

        self::assertSame(['d1' => FailureCause::Reviewer], $verdict->missed);
    }

    public function testAMissedDefectWhoseRangeIsNotInTheChangeIsABenchFailure(): void
    {
        $verdict = $this->classify(self::DEFECTS, RunOutcome::completed([]), new ChangeFiles(['src/a.js' => 5]));

        self::assertSame(['d1' => FailureCause::Bench], $verdict->missed);
    }

    public function testAFindingInARangePastTheEndOfTheFileIsABenchFailureNotACatch(): void
    {
        $findings = $this->scriptedFindings([['src/a.js', 5]]);

        $verdict = $this->classify(self::DEFECTS, RunOutcome::completed($findings), new ChangeFiles(['src/a.js' => 5]));

        self::assertSame([], $verdict->caught);
        self::assertSame(['d1' => FailureCause::Bench], $verdict->missed);
        self::assertSame($findings, $verdict->unmatched);
    }

    public function testAFindingInARangeEndingOnTheLastLineOfTheFileCatchesTheDefect(): void
    {
        $findings = $this->scriptedFindings([['src/a.js', 5]]);

        $verdict = $this->classify(self::DEFECTS, RunOutcome::completed($findings), new ChangeFiles(['src/a.js' => 6]));

        self::assertSame(['d1'], $verdict->caught);
        self::assertSame([], $verdict->missed);
    }

    public function testAMissedDefectOnAFileTheChangeLacksIsABenchFailure(): void
    {
        $verdict = $this->classify(self::DEFECTS, RunOutcome::completed([]), new ChangeFiles([]));

        self::assertSame(['d1' => FailureCause::Bench], $verdict->missed);
    }

    public function testAProviderFailureIsAHarnessFailureForEveryDefect(): void
    {
        $verdict = $this->classify(self::DEFECTS, RunOutcome::failed('provider timed out'));

        self::assertTrue($verdict->isHarnessFailure());
        self::assertSame('provider timed out', $verdict->harnessFailure);
        self::assertSame(['d1' => FailureCause::Harness], $verdict->missed);
    }

    /**
     * @param list<array{string, int}> $places
     *
     * @return list<Finding>
     */
    private function scriptedFindings(array $places): array
    {
        $findings = [];
        foreach ($places as $number => [$file, $line]) {
            $document = VerdictDocument::fromTheDesign()->withFinding('id', 'F'.($number + 1))->withFinding('file', $file)->withFinding('line', $line);
            $findings[] = $document->finding();
        }

        $json = json_encode(['verdict' => 'changes', 'findings' => $findings, 'resolved' => [], 'follow_ups' => []], JSON_THROW_ON_ERROR);

        return ScriptedReviewer::in(self::getContainer())->verdictFor($json)->findings;
    }

    private function classify(string $json, RunOutcome $outcome, ?ChangeFiles $change = null): RunVerdict
    {
        return new RunClassifier(new DefectMatcher())->classify(Expectations::fromJson($json), $change ?? new ChangeFiles(['src/a.js' => 10]), $outcome);
    }
}
