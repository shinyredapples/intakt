<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Platform\Contract\FigureKind;
use Intakt\Core\Platform\Contract\Figures;
use Intakt\Core\Review\Contract\BlockReasons;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FindingIds;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\RoundEnds;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\LocalGitForge\Infrastructure\VisiblePath;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewExit;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewOutput;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewOutputVerdict;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewReport;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Tests\Support\StoredSerializerFactory;
use JsonSchema\Validator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReviewReport::class)]
#[UsesClass(VisiblePath::class)]
#[UsesClass(ReviewExit::class)]
#[UsesClass(ReviewOutput::class)]
#[UsesClass(ReviewOutputVerdict::class)]
#[UsesClass(RoundTokens::class)]
#[UsesClass(BlockReasons::class)]
#[UsesClass(Cost::class)]
#[UsesClass(Figures::class)]
#[UsesClass(FigureKind::class)]
#[UsesClass(TokenFigures::class)]
#[UsesClass(FindingIds::class)]
final class ReviewReportTest extends TestCase
{
    private const string SCHEMA = __DIR__.'/../../../docs/user/review-output.schema.json';

    public function testTheTextOutputPrintsTheOutcomeTheActListAndTheTokens(): void
    {
        $lines = $this->report()->asLines($this->review(Decision::Changes));

        self::assertSame([
            'changes (round 1 counted)',
            'strong reviewer: changes, 1 findings counted',
            'standard reviewer: approve, 0 findings counted',
            'act list: 1 findings counted',
            'F1 P1 src/Feature.php:3: Missing check.',
            'tokens: 2 calls counted, 100 prompt, 20 completion, 120 total measured',
            'cost: 0.0001 USD estimated, price date unknown',
        ], $lines);
    }

    public function testAnActEntryThatMergesFindingsNamesTheIdsOfTheMergedFindings(): void
    {
        $merged = new Finding('F1', Severity::P1, 'src/Feature.php', 3, null, 'Missing check.', FixableBy::Builder, false, null, null)->withMerged(new FindingIds('F2', 'F4'));
        $review = $this->review(Decision::Changes);

        $lines = $this->report()->asLines(new ChangeReview($review->roundId, $review->round, $review->decision, $review->verdicts, [$merged], $review->tokens, $review->cost, $review->reasons));

        self::assertContains('F1+F2+F4 P1 src/Feature.php:3: Missing check.', $lines);
    }

    public function testAnActEntryWithAJudgesNotePrintsTheNoteOnTheLineAfterIt(): void
    {
        $lines = $this->report()->asLines($this->reviewOf($this->noted('Rejected: unreachable.')));

        self::assertSame(['F1 P1 src/Feature.php:3: Missing check.', 'judge: Rejected: unreachable.'], array_slice($lines, 4, 2));
    }

    public function testAnActEntryWithoutAJudgesNotePrintsNoJudgeLine(): void
    {
        $lines = $this->report()->asLines($this->review(Decision::Changes));

        self::assertSame([], array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'judge:')));
    }

    public function testTheJudgesNoteIsPrintedWithItsControlBytesVisible(): void
    {
        $lines = $this->report()->asLines($this->reviewOf($this->noted("Rejected.\e[2J<info>")));

        self::assertContains('judge: Rejected.\x1b[2J\<info\>', $lines);
    }

    public function testTheJsonActListRowCarriesTheJudgesNoteAndValidatesAgainstTheDocumentedSchema(): void
    {
        $review = $this->reviewOf($this->noted('Deferred: belongs to the retry task.'));

        self::assertSame('Deferred: belongs to the retry task.', $this->rowsOf($review)['act_list'][0]['judge_note'] ?? null);
        self::assertSame([], $this->schemaErrors($this->report()->asJson($review)));
    }

    public function testAJsonActListRowWithoutAJudgesNoteHasNoJudgeNoteKey(): void
    {
        $document = $this->rowsOf($this->review(Decision::Changes));

        self::assertArrayNotHasKey('judge_note', $document['act_list'][0]);
    }

    public function testTheSchemaRefusesAnEmptyJudgesNote(): void
    {
        $document = $this->rowsOf($this->review(Decision::Changes));
        $document['act_list'][0]['judge_note'] = '';

        self::assertNotSame([], $this->schemaErrors(json_encode($document, JSON_THROW_ON_ERROR)));
    }

    public function testAFailedReviewerIsPrintedAsFailedInPlainWordsWithoutAClassName(): void
    {
        $failed = $this->review(Decision::Approve)->blockedFor(RoundEnds::NoOutcomeInTime, 'correctness reviewer failed, stopped at its output limit');

        $lines = $this->report()->asLines($failed);

        self::assertContains('reason: correctness reviewer failed, stopped at its output limit', $lines);
        self::assertStringNotContainsString('Exception', implode("\n", $lines));
    }

    public function testTheTokenLineShowsTheCacheReadsAndWritesThePromptIncludes(): void
    {
        $lines = $this->report()->asLines($this->review(Decision::Changes, RoundTokens::of(3, new TokenFigures(39504, 38000, 1500, 0, 700))));

        self::assertContains('tokens: 3 calls counted, 39504 prompt (38000 cache read, 1500 cache write), 700 completion, 40204 total measured', $lines);
    }

    public function testTheTokenLineShowsCachedTokensAVendorDoesNotSplit(): void
    {
        $lines = $this->report()->asLines($this->review(Decision::Changes, RoundTokens::of(1, new TokenFigures(39504, 0, 0, 39500, 700))));

        self::assertContains('tokens: 1 calls counted, 39504 prompt (39500 cached), 700 completion, 40204 total measured', $lines);
    }

    public function testTheCostLineShowsTheRoundsCostInDollars(): void
    {
        $lines = $this->report()->asLines($this->review(Decision::Changes, cost: Cost::inUsd(0.045895)));

        self::assertSame('cost: 0.0459 USD estimated, price date unknown', array_last($lines));
    }

    public function testTheCostLineOfACostTooSmallForFourDecimalsIsNotPrintedAsZero(): void
    {
        $lines = $this->report()->asLines($this->review(Decision::Changes, cost: Cost::inUsd(0.000038)));

        self::assertSame('cost: less than 0.0001 USD estimated, price date unknown', array_last($lines));
    }

    public function testTheCostLineOfACostOfNothingIsPrintedAsZero(): void
    {
        $lines = $this->report()->asLines($this->review(Decision::Changes, cost: Cost::inUsd(0.0)));

        self::assertSame('cost: 0.0000 USD estimated, price date unknown', array_last($lines));
    }

    public function testTheCostLineSaysTheCostIsUnknownAndNamesTheModelsWithoutAPrice(): void
    {
        $lines = $this->report()->asLines($this->review(Decision::Changes, cost: Cost::inUsd(0.01)->plus(Cost::unknown('local-small', 'gpt-5'))));

        self::assertSame('cost: unknown, no price for local-small, gpt-5', array_last($lines));
    }

    public function testTheCostLineNamesTheDayOfThePriceList(): void
    {
        $lines = $this->report()->asLines($this->review(Decision::Changes, cost: Cost::inUsd(0.0459, '2026-11-13')));

        self::assertSame('cost: 0.0459 USD estimated, prices of 2026-11-13', array_last($lines));
    }

    public function testTheCostLineOfAnUnknownCostCarriesNoKindBecauseItShowsNoFigure(): void
    {
        $lines = $this->report()->asLines($this->review(Decision::Changes, cost: Cost::unknown()));

        self::assertSame('cost: unknown', array_last($lines));
    }

    public function testTheTokensAndTheCostAreNeverAddedIntoOneLine(): void
    {
        $lines = $this->report()->asLines($this->review(Decision::Changes));

        self::assertCount(1, array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'tokens: ')));
        self::assertCount(1, array_filter($lines, static fn (string $line): bool => str_starts_with($line, 'cost: ')));
    }

    public function testTheJsonOutputCarriesTheKindOfTheRoundTheTokensAndTheCost(): void
    {
        $document = $this->documentOf($this->review(Decision::Changes, cost: Cost::inUsd(0.0459, '2026-11-13')));

        self::assertSame(['round' => 'counted', 'findings' => 'counted', 'calls' => 'counted', 'tokens' => 'measured', 'cost' => 'estimated'], $document['kinds']);
        self::assertIsArray($document['cost']);
        self::assertSame('2026-11-13', $document['cost']['price_date']);
    }

    public function testTheCallCountComesFromTheLedgerSoItIsMarkedCountedBesideTheMeasuredTokens(): void
    {
        $lines = $this->report()->asLines($this->review(Decision::Changes));

        self::assertContains('tokens: 2 calls counted, 100 prompt, 20 completion, 120 total measured', $lines);
    }

    public function testTheFindingCountsOfEachReviewerAndOfTheActListAreMarkedCounted(): void
    {
        $lines = $this->report()->asLines($this->review(Decision::Changes));

        self::assertContains('strong reviewer: changes, 1 findings counted', $lines);
        self::assertContains('act list: 1 findings counted', $lines);
    }

    public function testTheSchemaRefusesCallsMarkedAsMeasured(): void
    {
        $document = $this->documentOf($this->review(Decision::Approve));
        self::assertIsArray($document['kinds']);
        $document['kinds']['calls'] = 'measured';

        self::assertNotSame([], $this->schemaErrors(json_encode($document, JSON_THROW_ON_ERROR)));
    }

    public function testTheSchemaRefusesAnOutputWithoutItsKinds(): void
    {
        $document = $this->documentOf($this->review(Decision::Approve));
        unset($document['kinds']);

        self::assertNotSame([], $this->schemaErrors(json_encode($document, JSON_THROW_ON_ERROR)));
    }

    public function testTheSchemaRefusesACostMarkedAsMeasured(): void
    {
        $document = $this->documentOf($this->review(Decision::Approve));
        $document['kinds'] = ['round' => 'counted', 'findings' => 'counted', 'calls' => 'counted', 'tokens' => 'measured', 'cost' => 'measured'];

        self::assertNotSame([], $this->schemaErrors(json_encode($document, JSON_THROW_ON_ERROR)));
    }

    public function testTheJsonOutputOfARoundWithAnUnknownCostValidatesAgainstTheDocumentedSchema(): void
    {
        $json = $this->report()->asJson($this->review(Decision::Changes, cost: Cost::unknown('local-small')));

        self::assertSame([], $this->schemaErrors($json));
    }

    // One decision stands for all three, because the schema checks the decision as one enum; blocked is the one that also carries reasons.
    public function testTheJsonOutputValidatesAgainstTheDocumentedSchema(): void
    {
        $json = $this->report()->asJson($this->review(Decision::Blocked));

        self::assertSame([], $this->schemaErrors($json));
    }

    public function testTheSchemaRefusesAnOutputWithoutItsTokens(): void
    {
        $document = $this->documentOf($this->review(Decision::Approve));
        unset($document['tokens']);

        self::assertNotSame([], $this->schemaErrors(json_encode($document, JSON_THROW_ON_ERROR)));
    }

    public function testTheSchemaRefusesAnOutputWithoutItsCost(): void
    {
        $document = $this->documentOf($this->review(Decision::Approve));
        unset($document['cost']);

        self::assertNotSame([], $this->schemaErrors(json_encode($document, JSON_THROW_ON_ERROR)));
    }

    private function noted(string $note): Finding
    {
        return new Finding('F1', Severity::P1, 'src/Feature.php', 3, null, 'Missing check.', FixableBy::Builder, false, null, null)->withJudgeNote($note);
    }

    private function reviewOf(Finding $entry): ChangeReview
    {
        $review = $this->review(Decision::Changes);

        return new ChangeReview($review->roundId, $review->round, $review->decision, $review->verdicts, [$entry], $review->tokens, $review->cost, $review->reasons);
    }

    private function report(): ReviewReport
    {
        return new ReviewReport(StoredSerializerFactory::create());
    }

    private function review(Decision $decision, ?RoundTokens $tokens = null, ?Cost $cost = null): ChangeReview
    {
        $finding = new Finding('F1', Severity::P1, 'src/Feature.php', 3, null, 'Missing check.', FixableBy::Builder, false, null, null);
        $verdicts = [
            new PanelVerdict(Tier::Strong, new Verdict(Decision::Changes, [$finding], [], [])),
            new PanelVerdict(Tier::Standard, new Verdict(Decision::Approve, [], [], [])),
        ];
        $tokens ??= RoundTokens::of(2, new TokenFigures(100, 0, 0, 0, 20));

        return new ChangeReview('0192a000-0000-7000-8000-000000000000', 1, $decision, $verdicts, [$finding], $tokens, $cost ?? Cost::inUsd(0.00014), Decision::Blocked === $decision ? new BlockReasons('correctness: provider refused the key') : new BlockReasons());
    }

    /**
     * @return array<mixed>
     */
    private function documentOf(ChangeReview $review): array
    {
        $document = json_decode($this->report()->asJson($review), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        return $document;
    }

    /**
     * @return array{act_list: non-empty-list<array<string, mixed>>}
     */
    private function rowsOf(ChangeReview $review): array
    {
        /** @var array{act_list: non-empty-list<array<string, mixed>>} $document */
        $document = $this->documentOf($review);

        return $document;
    }

    /**
     * @return list<mixed>
     */
    private function schemaErrors(string $output): array
    {
        $validator = new Validator();
        $document = json_decode($output, flags: JSON_THROW_ON_ERROR);
        $validator->validate($document, json_decode((string) file_get_contents(self::SCHEMA)));

        return array_values($validator->getErrors());
    }
}
