<?php

declare(strict_types=1);

namespace Intakt\Tests\SampleRepository;

use Intakt\Core\Bench\Domain\BenchFigures;
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
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

#[CoversClass(RunClassifier::class)]
#[CoversClass(BenchFigures::class)]
#[UsesClass(DefectMatcher::class)]
#[UsesClass(Expectations::class)]
#[UsesClass(PlantedDefect::class)]
#[UsesClass(ChangeFiles::class)]
#[UsesClass(RunOutcome::class)]
#[UsesClass(RunVerdict::class)]
#[UsesClass(FailureCause::class)]
final class ScriptedBenchRunTest extends KernelTestCase
{
    private const string SAMPLE = __DIR__.'/../../fixtures/sample-repository';

    public function testAReviewerFindingEveryPlantedLineCatchesAllDefectsWithoutFalseAlarms(): void
    {
        $figures = $this->reviewBench(fn (Expectations $expectations): RunOutcome => RunOutcome::completed($this->findingsOn($expectations)));

        self::assertSame(5, $figures->planted);
        self::assertSame(5, $figures->caught);
        self::assertSame(3, $figures->cleanRuns);
        self::assertSame(1.0, $figures->detectionRate());
        self::assertSame(0.0, $figures->falseAlarmRate());
    }

    public function testAReviewerThatFindsNothingMissesEveryDefectAsTheReviewersMiss(): void
    {
        $figures = $this->reviewBench(fn (): RunOutcome => RunOutcome::completed([]));

        self::assertSame(5, $figures->missedByReviewer);
        self::assertSame(0, $figures->missedByBench);
        self::assertSame(0.0, $figures->detectionRate());
    }

    public function testAFindingOnACleanBenchChangeCountsAsAFalseAlarm(): void
    {
        $figures = $this->reviewBench(fn (Expectations $expectations): RunOutcome => RunOutcome::completed(
            $expectations->isDefectFree() ? $this->scripted([['README.md', 1]]) : $this->findingsOn($expectations),
        ));

        self::assertSame(3, $figures->cleanRunsWithFalseAlarm);
        self::assertSame(1.0, $figures->falseAlarmRate());
        self::assertSame(1.0, $figures->detectionRate());
    }

    public function testAFailedProviderRunIsListedAndLeftOutOfTheRates(): void
    {
        $figures = $this->reviewBench(fn (Expectations $expectations): RunOutcome => [] === $expectations->defects || 'coupon-save10-missing' !== $expectations->defects[0]->id
            ? RunOutcome::completed($this->findingsOn($expectations))
            : RunOutcome::failed('provider timed out'));

        self::assertSame(['provider timed out'], $figures->harnessFailures);
        self::assertSame(4, $figures->planted);
        self::assertSame(1.0, $figures->detectionRate());
    }

    /**
     * @param callable(Expectations): RunOutcome $review
     */
    private function reviewBench(callable $review): BenchFigures
    {
        $classifier = new RunClassifier(new DefectMatcher());
        $verdicts = [];
        foreach (new Finder()->directories()->depth(0)->in(self::SAMPLE.'/bench')->sortByName() as $change) {
            $expectations = Expectations::fromJson(new Filesystem()->readFile($change->getPathname().'/expectations.json'));
            $verdicts[] = $classifier->classify($expectations, $this->filesOf($change->getPathname().'/tree'), $review($expectations));
        }

        return BenchFigures::from($verdicts);
    }

    private function filesOf(string $tree): ChangeFiles
    {
        $lineCounts = [];
        foreach ([self::SAMPLE.'/base', $tree] as $directory) {
            foreach (new Finder()->files()->in($directory) as $file) {
                $lineCounts[$file->getRelativePathname()] = substr_count(rtrim($file->getContents(), "\n"), "\n") + 1;
            }
        }

        return new ChangeFiles($lineCounts);
    }

    /**
     * @return list<Finding>
     */
    private function findingsOn(Expectations $expectations): array
    {
        return $this->scripted(array_map(static fn (PlantedDefect $defect): array => [$defect->file, $defect->from], $expectations->defects));
    }

    /**
     * @param list<array{string, int}> $places
     *
     * @return list<Finding>
     */
    private function scripted(array $places): array
    {
        $findings = [];
        foreach ($places as $number => [$file, $line]) {
            $findings[] = VerdictDocument::fromTheDesign()->withFinding('id', 'F'.($number + 1))->withFinding('file', $file)->withFinding('line', $line)->finding();
        }

        $json = json_encode(['verdict' => [] === $findings ? 'approve' : 'changes', 'findings' => $findings, 'resolved' => [], 'follow_ups' => []], JSON_THROW_ON_ERROR);

        return ScriptedReviewer::in(self::getContainer())->verdictFor($json)->findings;
    }
}
