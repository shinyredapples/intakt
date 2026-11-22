<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\LocalGitForge;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Review\Contract\BlockReasons;
use Intakt\Core\Review\Contract\ChangeReview;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\Evidence;
use Intakt\Core\Review\Contract\Finding;
use Intakt\Core\Review\Contract\FindingIds;
use Intakt\Core\Review\Contract\FixableBy;
use Intakt\Core\Review\Contract\PanelVerdict;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\Contract\Severity;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewExit;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewOutput;
use Intakt\Plugin\LocalGitForge\UseCase\ReviewChange\ReviewOutputVerdict;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

#[CoversClass(ReviewOutput::class)]
#[CoversClass(ReviewOutputVerdict::class)]
#[UsesClass(BlockReasons::class)]
#[UsesClass(ReviewExit::class)]
#[UsesClass(Cost::class)]
#[UsesClass(FindingIds::class)]
final class ReviewOutputTest extends TestCase
{
    public function testTheOutputCarriesTheExitCodeAndTheFindingsCountPerVerdict(): void
    {
        $output = $this->whenTheReviewIsWritten();

        self::assertSame(10, $output['exit_code']);
        self::assertSame([['tier' => 'strong', 'decision' => 'changes', 'findings' => 1], ['tier' => 'standard', 'decision' => 'approve', 'findings' => 0]], $output['verdicts']);
    }

    public function testTheActListRowsAreTheFindingsWithTheDocumentedFieldsOnly(): void
    {
        $output = $this->whenTheReviewIsWritten();

        self::assertSame([['id' => 'F1', 'severity' => 'P1', 'file' => 'src/Feature.php', 'line' => 3, 'symbol' => null, 'text' => 'Missing check.', 'fixable_by' => 'builder', 'merged' => []]], $output['act_list']);
    }

    public function testAnActEntryThatMergesFindingsCarriesTheIdsOfTheMergedFindings(): void
    {
        $output = $this->whenTheReviewIsWritten(['F2', 'F4']);

        self::assertIsArray($output['act_list']);
        self::assertIsArray($output['act_list'][0]);
        self::assertSame(['id' => 'F1', 'merged' => ['F2', 'F4']], array_intersect_key($output['act_list'][0], ['id' => true, 'merged' => true]));
    }

    public function testTheRoundItsDecisionTheTokensTheCostAndTheReasonsKeepTheirSnakeCaseKeys(): void
    {
        $output = $this->whenTheReviewIsWritten();

        self::assertSame(
            ['round_id' => 'round-1', 'round' => 2, 'decision' => 'changes', 'tokens' => ['calls' => 2, 'prompt' => 100, 'cache_read' => 60, 'cache_write' => 30, 'cached' => 5, 'completion' => 20, 'total' => 120], 'cost' => ['usd' => 0.045895, 'unpriced' => [], 'price_date' => null], 'reasons' => ['a reason'], 'kinds' => ['round' => 'counted', 'findings' => 'counted', 'calls' => 'counted', 'tokens' => 'measured', 'cost' => 'estimated']],
            array_diff_key($output, ['exit_code' => true, 'verdicts' => true, 'act_list' => true]),
        );
    }

    /**
     * @param list<string> $merged
     *
     * @return array<mixed>
     */
    private function whenTheReviewIsWritten(array $merged = []): array
    {
        $finding = new Finding('F1', Severity::P1, 'src/Feature.php', 3, null, 'Missing check.', FixableBy::Builder, true, 'R1', new Evidence('bin/check', 'failed'))->withMerged(new FindingIds(...$merged));
        $verdicts = [
            new PanelVerdict(Tier::Strong, new Verdict(Decision::Changes, [$finding], [], [])),
            new PanelVerdict(Tier::Standard, new Verdict(Decision::Approve, [], [], [])),
        ];
        $review = new ChangeReview('round-1', 2, Decision::Changes, $verdicts, [$finding], new RoundTokens(2, 100, 60, 30, 5, 20, 120), Cost::inUsd(0.045895), new BlockReasons('a reason'));

        $json = StoredSerializerFactory::create()->serialize(ReviewOutput::of($review), JsonEncoder::FORMAT, [AbstractNormalizer::ATTRIBUTES => ReviewOutput::FIELDS]);
        $output = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($output);

        return $output;
    }
}
