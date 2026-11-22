<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Bench\UseCase\RunBench;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Bench\Contract\BenchProvenance;
use Intakt\Core\Bench\Contract\BenchReview;
use Intakt\Core\Bench\Contract\BenchReviewerResult;
use Intakt\Core\Bench\Contract\RepeatedChange;
use Intakt\Core\Bench\Domain\FailureCause;
use Intakt\Core\Bench\Domain\RunVerdict;
use Intakt\Core\Bench\UseCase\ReviewBenchChange\ReadBenchProvenance;
use Intakt\Core\Bench\UseCase\RunBench\BenchResultFile;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\ModelNames;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\PromptHashes;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Infrastructure\HashedReviewPrompts;
use Intakt\Core\Review\Infrastructure\JudgePrompt;
use Intakt\Core\Review\Infrastructure\ReviewerPrompt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

/**
 * @phpstan-import-type ResultDocument from BenchResultFile
 */
#[CoversClass(BenchResultFile::class)]
#[UsesClass(ReadBenchProvenance::class)]
#[UsesClass(BenchProvenance::class)]
#[UsesClass(HashedReviewPrompts::class)]
#[UsesClass(PromptHashes::class)]
#[UsesClass(ReviewerPrompt::class)]
#[UsesClass(JudgePrompt::class)]
#[UsesClass(BenchReview::class)]
#[UsesClass(RepeatedChange::class)]
#[UsesClass(BenchReviewerResult::class)]
#[UsesClass(RunVerdict::class)]
#[UsesClass(RoundTokens::class)]
final class BenchResultFileTest extends TestCase
{
    private const string FIXTURES = __DIR__.'/../../../../../fixtures/sample-repository';

    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/intakt-bench-result-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testTheResultFileNamesTheIntaktVersionThePromptHashesAndTheBenchRepository(): void
    {
        $result = $this->written([]);

        $provenance = new ReadBenchProvenance(new HashedReviewPrompts(), self::FIXTURES)->read();
        self::assertSame($provenance->intaktVersion, $result['intakt_version']);
        self::assertSame(['reviewer' => hash('sha256', ReviewerPrompt::INSTRUCTIONS), 'judge' => hash('sha256', JudgePrompt::INSTRUCTIONS)], $result['prompts']);
        self::assertSame($provenance->benchRepositoryHash, $result['bench_repository']);
        self::assertSame('alpha', $result['project']);
    }

    public function testTheResultFileIsNamedAfterTheMomentOfTheRunInUtc(): void
    {
        $path = $this->resultFile()->write('alpha', []);

        self::assertMatchesRegularExpression('/^20261115T103000Z-[0-9a-f]{6}\.json\z/', basename($path));
        self::assertSame($this->directory, dirname($path));
        self::assertSame('2026-11-15T10:30:00+00:00', $this->decoded($path)['ran_at']);
    }

    public function testTwoRunsInTheSameSecondWriteTwoFiles(): void
    {
        $file = $this->resultFile();

        self::assertNotSame($file->write('alpha', []), $file->write('alpha', []));
        self::assertCount(2, new Finder()->files()->in($this->directory)->name('*.json'));
    }

    public function testEachRunOfAChangeHoldsItsDecisionAndItsActList(): void
    {
        $result = $this->written([new BenchReview('free-shipping', Decision::Changes, [], [$this->finding('F1', 3)])]);

        self::assertSame([[
            'decision' => 'changes',
            'reviewers' => [],
            'act_list' => [['id' => 'F1', 'severity' => 'P1', 'file' => 'src/shipping.js', 'line' => 3, 'text' => 'Text of F1.']],
        ]], $result['changes'][0]['runs']);
    }

    public function testTheResultFileRecordsEachChangesRunsAndWhetherTheyAgreed(): void
    {
        $caught = new BenchReviewerResult('role-1', Tier::Standard, new RunVerdict(false, ['boundary'], [], [], [], null), new RoundTokens(1, 100, 0, 0, 0, 10, 110), 1000, new ModelNames());
        $missed = new BenchReviewerResult('role-1', Tier::Standard, new RunVerdict(false, [], ['boundary' => FailureCause::Reviewer], [], [], null), new RoundTokens(1, 100, 0, 0, 0, 10, 110), 1000, new ModelNames());
        $agreeing = new RepeatedChange('free-shipping', [new BenchReview('free-shipping', Decision::Changes, [$caught], []), new BenchReview('free-shipping', Decision::Changes, [$caught], [])]);
        $disagreeing = new RepeatedChange('price-list', [new BenchReview('price-list', Decision::Changes, [$caught], []), new BenchReview('price-list', Decision::Changes, [$missed], [])]);

        $changes = $this->decoded($this->resultFile()->write('alpha', [$agreeing, $disagreeing, new RepeatedChange('round-total', [new BenchReview('round-total', Decision::Approve, [], [])])]))['changes'];

        self::assertSame([['free-shipping', true, 2], ['price-list', false, 2], ['round-total', null, 1]], array_map(static fn (array $change): array => [$change['change'], $change['agreed'], count($change['runs'])], $changes));
    }

    public function testEachReviewerHoldsItsMatchedDefectsMissesFalseAlarmsTokensAndDuration(): void
    {
        $verdict = new RunVerdict(false, ['caught-one'], ['missed-one' => FailureCause::Reviewer, 'impossible' => FailureCause::Bench], [$this->finding('F2', 9)], [$this->finding('F3', 12)], null);
        $reviewer = new BenchReviewerResult('role-1', Tier::Standard, $verdict, new RoundTokens(2, 500, 0, 0, 0, 40, 540), 3000, new ModelNames());

        $result = $this->written([new BenchReview('free-shipping', Decision::Changes, [$reviewer], [])]);

        $written = $result['changes'][0]['runs'][0]['reviewers'][0];
        self::assertSame(['role-1', 'standard'], [$written['role'], $written['tier']]);
        self::assertSame(['caught-one'], $written['caught']);
        self::assertSame([['id' => 'missed-one', 'cause' => 'reviewer'], ['id' => 'impossible', 'cause' => 'bench']], $written['missed']);
        self::assertSame(['F2'], array_column($written['false_alarms'], 'id'));
        self::assertSame(['F3'], array_column($written['unmatched'], 'id'));
        self::assertNull($written['harness_failure']);
        self::assertSame(['calls' => 2, 'prompt' => 500, 'completion' => 40, 'total' => 540], $written['tokens']);
        self::assertSame(3000, $written['milliseconds']);
    }

    public function testAReviewerWithoutAnEndHasNoDuration(): void
    {
        $reviewer = new BenchReviewerResult('role-1', Tier::Standard, new RunVerdict(true, [], [], [], [], null), new RoundTokens(0, 0, 0, 0, 0, 0, 0), null, new ModelNames());

        $result = $this->written([new BenchReview('round-total', Decision::Approve, [$reviewer], [])]);

        self::assertNull($result['changes'][0]['runs'][0]['reviewers'][0]['milliseconds']);
    }

    public function testAHarnessFailureIsWrittenWithItsReasonAndItsMissesAsTheHarnesses(): void
    {
        $verdict = new RunVerdict(false, [], ['coupon' => FailureCause::Harness], [], [], 'stopped at its output limit');
        $reviewer = new BenchReviewerResult('role-1', Tier::Standard, $verdict, new RoundTokens(1, 100, 0, 0, 0, 0, 100), 500, new ModelNames());

        $result = $this->written([new BenchReview('coupon-code', Decision::Blocked, [$reviewer], [])]);

        $written = $result['changes'][0]['runs'][0]['reviewers'][0];
        self::assertSame('stopped at its output limit', $written['harness_failure']);
        self::assertSame([['id' => 'coupon', 'cause' => 'harness']], $written['missed']);
    }

    /**
     * @param list<BenchReview> $reviews
     *
     * @return ResultDocument
     */
    private function written(array $reviews): array
    {
        return $this->decoded($this->resultFile()->write('alpha', array_map(static fn (BenchReview $review): RepeatedChange => new RepeatedChange($review->change, [$review]), $reviews)));
    }

    private function resultFile(): BenchResultFile
    {
        return new BenchResultFile(new ReadBenchProvenance(new HashedReviewPrompts(), self::FIXTURES), new MockClock('2026-11-15 11:30:00', 'Europe/Berlin'), $this->directory);
    }

    /**
     * @return ResultDocument
     */
    private function decoded(string $path): array
    {
        /** @var ResultDocument $decoded */
        $decoded = json_decode(new Filesystem()->readFile($path), true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function finding(string $id, int $line): Finding
    {
        return new Finding($id, Severity::P1, 'src/shipping.js', $line, null, 'Text of '.$id.'.', FixableBy::Builder, false, null, null);
    }
}
