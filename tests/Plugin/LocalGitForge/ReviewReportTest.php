<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\PanelVerdict;
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
#[UsesClass(Cost::class)]
#[UsesClass(TokenFigures::class)]
final class ReviewReportTest extends TestCase
{
    private const string SCHEMA = __DIR__.'/../../../docs/user/review-output.schema.json';

    public function testTheTextOutputPrintsTheOutcomeTheActListAndTheTokens(): void
    {
        $lines = $this->report()->asLines($this->review(Decision::Changes));

        self::assertSame([
            'changes (round 1)',
            'strong reviewer: changes, 1 findings',
            'standard reviewer: approve, 0 findings',
            'act list: 1 findings',
            'F1 P1 src/Feature.php:3: Missing check.',
            'tokens: 2 calls, 100 prompt, 20 completion, 120 total',
            'cost: 0.0001 USD',
        ], $lines);
    }

    public function testAnActEntryThatMergesFindingsNamesTheIdsOfTheMergedFindings(): void
    {
        $merged = new Finding('F1', Severity::P1, 'src/Feature.php', 3, null, 'Missing check.', FixableBy::Builder, false, null, null)->withMerged(['F2', 'F4']);
        $review = $this->review(Decision::Changes);

        $lines = $this->report()->asLines(new ChangeReview($review->roundId, $review->round, $review->decision, $review->verdicts, [$merged], $review->tokens, $review->cost, $review->reasons));

        self::assertContains('F1+F2+F4 P1 src/Feature.php:3: Missing check.', $lines);
    }

    public function testAFailedReviewerIsPrintedAsFailedInPlainWordsWithoutAClassName(): void
    {
        $failed = $this->review(Decision::Approve)->blockedFor('correctness reviewer failed, stopped at its output limit');

        $lines = $this->report()->asLines($failed);

        self::assertContains('reason: correctness reviewer failed, stopped at its output limit', $lines);
        self::assertStringNotContainsString('Exception', implode("\n", $lines));
    }

    public function testTheTokenLineShowsTheCacheReadsAndWritesThePromptIncludes(): void
    {
        $lines = $this->report()->asLines($this->review(Decision::Changes, RoundTokens::of(3, new TokenFigures(39504, 38000, 1500, 0, 700))));

        self::assertContains('tokens: 3 calls, 39504 prompt (38000 cache read, 1500 cache write), 700 completion, 40204 total', $lines);
    }

    public function testTheTokenLineShowsCachedTokensAVendorDoesNotSplit(): void
    {
        $lines = $this->report()->asLines($this->review(Decision::Changes, RoundTokens::of(1, new TokenFigures(39504, 0, 0, 39500, 700))));

        self::assertContains('tokens: 1 calls, 39504 prompt (39500 cached), 700 completion, 40204 total', $lines);
    }

    public function testTheCostLineShowsTheRoundsCostInDollars(): void
    {
        $lines = $this->report()->asLines($this->review(Decision::Changes, cost: Cost::inUsd(0.045895)));

        self::assertSame('cost: 0.0459 USD', array_last($lines));
    }

    public function testTheCostLineOfACostTooSmallForFourDecimalsIsNotPrintedAsZero(): void
    {
        $lines = $this->report()->asLines($this->review(Decision::Changes, cost: Cost::inUsd(0.000038)));

        self::assertSame('cost: less than 0.0001 USD', array_last($lines));
    }

    public function testTheCostLineOfACostOfNothingIsPrintedAsZero(): void
    {
        $lines = $this->report()->asLines($this->review(Decision::Changes, cost: Cost::inUsd(0.0)));

        self::assertSame('cost: 0.0000 USD', array_last($lines));
    }

    public function testTheCostLineSaysTheCostIsUnknownAndNamesTheModelsWithoutAPrice(): void
    {
        $lines = $this->report()->asLines($this->review(Decision::Changes, cost: Cost::inUsd(0.01)->plus(Cost::unknown('local-small', 'gpt-5'))));

        self::assertSame('cost: unknown, no price for local-small, gpt-5', array_last($lines));
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

    private function review(Decision $decision, ?RoundTokens $tokens = null, ?Cost $cost = null): ChangeReview
    {
        $finding = new Finding('F1', Severity::P1, 'src/Feature.php', 3, null, 'Missing check.', FixableBy::Builder, false, null, null);
        $verdicts = [
            new PanelVerdict(Tier::Strong, new Verdict(Decision::Changes, [$finding], [], [])),
            new PanelVerdict(Tier::Standard, new Verdict(Decision::Approve, [], [], [])),
        ];
        $tokens ??= RoundTokens::of(2, new TokenFigures(100, 0, 0, 0, 20));

        return new ChangeReview('0192a000-0000-7000-8000-000000000000', 1, $decision, $verdicts, [$finding], $tokens, $cost ?? Cost::inUsd(0.00014), Decision::Blocked === $decision ? ['correctness: provider refused the key'] : []);
    }

    private function report(): ReviewReport
    {
        return new ReviewReport(StoredSerializerFactory::create());
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
