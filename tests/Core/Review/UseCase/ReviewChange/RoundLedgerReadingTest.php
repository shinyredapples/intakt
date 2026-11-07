<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Budget\Contract\Cost;
use Intakt\Core\Budget\Infrastructure\ConfiguredPrices;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\JudgeDoneFinding;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenFigures;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenUsageRow;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageSettled;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageStarted;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageUnsettled;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Support\KernelTestCase;
use Intakt\Tests\Support\StoredSerializerFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\AI\Platform\Bridge\ClaudeCode\TokenUsageExtractor as ClaudeCodeUsage;
use Symfony\AI\Platform\Bridge\Generic\Completions\TokenUsageExtractor as CompletionsUsage;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

#[Group('persistence')]
#[CoversClass(RoundLedgerReading::class)]
#[CoversClass(LedgerPayloads::class)]
#[UsesClass(RoundTokens::class)]
#[UsesClass(Cost::class)]
#[UsesClass(ConfiguredPrices::class)]
#[UsesClass(JudgeDone::class)]
#[UsesClass(JudgeDoneFinding::class)]
#[UsesClass(UsageStarted::class)]
#[UsesClass(UsageSettled::class)]
#[UsesClass(UsageUnsettled::class)]
#[UsesClass(TokenUsageRow::class)]
#[UsesClass(TokenFigures::class)]
final class RoundLedgerReadingTest extends KernelTestCase
{
    private const string ROUND = '0192a000-0000-7000-8000-000000000001';

    private const string OTHER_ROUND = '0192a000-0000-7000-8000-000000000002';

    private Ledger $ledger;

    protected function setUp(): void
    {
        $this->ledger = new RoundFixture(self::getContainer())->ledger();
    }

    public function testTheTokensOfACallRecordedTheWayUsageLedgerRecordsItAreCounted(): void
    {
        $this->recordCall('c1', self::ROUND, 'settled');

        self::assertSame([1, 100, 20, 120], $this->tokensOf(self::ROUND));
    }

    public function testAnAnthropicCallCountsItsCacheReadsAndWritesInThePromptAndSumsTheTotal(): void
    {
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard', 'round' => self::ROUND]);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'settled', 'usages' => [['account' => 'anthropic.default', 'prompt' => 4, 'cached' => 39500, 'cache_creation' => 1500, 'cache_read' => 38000, 'total' => null] + $this->usage()]]);

        $tokens = $this->reading()->tokensOf(self::ROUND);

        self::assertEquals(new RoundTokens(1, 39504, 38000, 1500, 0, 20, 39524), $tokens);
    }

    public function testTheTokensAnUnsettledCallSpentAreCounted(): void
    {
        $this->recordCall('c1', self::ROUND, 'unsettled');

        self::assertSame([1, 100, 20, 120], $this->tokensOf(self::ROUND));
    }

    public function testACallStartedWithoutARoundIdCountsForNoRound(): void
    {
        $this->recordCall('c1', null, 'settled');

        self::assertSame([0, 0, 0, 0], $this->tokensOf(self::ROUND));
    }

    public function testTwoRoundsOfOneProjectWhoseCallsInterleaveEachCountOnlyTheirOwn(): void
    {
        $this->append(LedgerEventKind::ReviewStarted, ['round' => self::ROUND, 'project' => 'alpha', 'reviewers' => 1]);
        $this->append(LedgerEventKind::ReviewStarted, ['round' => self::OTHER_ROUND, 'project' => 'alpha', 'reviewers' => 1]);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'a1', 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard', 'round' => self::ROUND]);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'b1', 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard', 'round' => self::OTHER_ROUND]);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'b1', 'phase' => 'settled', 'usages' => [['account' => null, 'prompt' => 500, 'completion' => 50, 'total' => 550]]]);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'a1', 'phase' => 'settled', 'usages' => [['account' => null, 'prompt' => 100, 'completion' => 20, 'total' => 120]]]);

        self::assertSame([1, 100, 20, 120], $this->tokensOf(self::ROUND));
        self::assertSame([1, 500, 50, 550], $this->tokensOf(self::OTHER_ROUND));
    }

    public function testAUsageRowThatIsNotJsonIsSkipped(): void
    {
        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::UsageRecorded, '{"call": "c1", "phase": "settled"'))->succeeded());
        $this->recordCall('c1', self::ROUND, 'settled');

        self::assertSame([1, 100, 20, 120], $this->tokensOf(self::ROUND));
    }

    public function testASettledRowWithATokenCountThatIsNotANumberIsSkipped(): void
    {
        $this->recordCall('c1', self::ROUND, 'settled');
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'settled', 'usages' => [['prompt' => 'many'] + $this->usage()]]);

        self::assertSame([1, 100, 20, 120], $this->tokensOf(self::ROUND));
    }

    public function testAStartedRowOfAnUnknownTierIsSkipped(): void
    {
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'started', 'project' => 'alpha', 'tier' => 'huge', 'round' => self::ROUND]);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'settled', 'usages' => [$this->usage()]]);

        self::assertSame([0, 0, 0, 0], $this->tokensOf(self::ROUND));
    }

    public function testARoundsCostIsItsCallsPricedByTheirModel(): void
    {
        $this->recordCall('c1', self::ROUND, 'settled');
        $this->recordCall('c2', self::ROUND, 'unsettled');

        self::assertEqualsWithDelta(2 * (100 * 2.0 + 20 * 10.0) / 1_000_000, $this->reading()->costOf(self::ROUND)->usd, 1e-12);
    }

    public function testARoundWithACallOfAModelWithoutAPriceHasAnUnknownCostNamingTheModel(): void
    {
        $this->recordCall('c1', self::ROUND, 'settled');
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c2', 'phase' => 'started', 'project' => 'alpha', 'tier' => 'light', 'round' => self::ROUND, 'model' => 'small']);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c2', 'phase' => 'settled', 'usages' => [$this->usage()]]);

        $cost = $this->reading()->costOf(self::ROUND);

        self::assertSame([null, ['small']], [$cost->usd, $cost->unpriced]);
    }

    public function testAClaudeCodeCallWhoseAnswerNamesNoModelIsPricedByTheModelItsTierResolvedTo(): void
    {
        $usage = new ClaudeCodeUsage()->extract(new InMemoryRawResult(['usage' => ['input_tokens' => 100, 'output_tokens' => 20]]));

        $this->recordAnswer('c1', 'big', $usage);

        self::assertEqualsWithDelta((100 * 2.0 + 20 * 10.0) / 1_000_000, $this->reading()->costOf(self::ROUND)->usd, 1e-12);
    }

    public function testAnApiCallAnsweredByADatedSnapshotIsPricedByTheModelItsTierNamed(): void
    {
        $usage = new CompletionsUsage()->extract(new InMemoryRawResult(['model' => 'big-2026-08-07', 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20, 'total_tokens' => 120]]));

        $this->recordAnswer('c1', 'big', $usage);

        self::assertEqualsWithDelta((100 * 2.0 + 20 * 10.0) / 1_000_000, $this->reading()->costOf(self::ROUND)->usd, 1e-12);
    }

    public function testACallStartedBeforeTheModelWasKeptHasAnUnknownCostSayingSo(): void
    {
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard', 'round' => self::ROUND]);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => 'c1', 'phase' => 'settled', 'usages' => [$this->usage()]]);

        $cost = $this->reading()->costOf(self::ROUND);

        self::assertSame([null, [ConfiguredPrices::NO_MODEL]], [$cost->usd, $cost->unpriced]);
    }

    public function testARoundWithoutCallsCostsNothing(): void
    {
        $this->recordCall('c1', self::OTHER_ROUND, 'settled');

        self::assertSame(0.0, $this->reading()->costOf(self::ROUND)->usd);
    }

    public function testTheActIdsOfAJudgeDoneRowInTodaysFormatAreRead(): void
    {
        $this->append(LedgerEventKind::JudgeDone, ['round' => self::OTHER_ROUND, 'act' => ['F9'], 'follow_ups' => [], 'findings' => []]);
        $this->append(LedgerEventKind::JudgeDone, $this->judgeDone(self::ROUND));

        self::assertSame(['F1', 'F3'], $this->reading()->actIdsOf(self::ROUND));
    }

    public function testAJudgeDoneRowThatIsNotJsonIsSkipped(): void
    {
        self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::JudgeDone, '{"round": "'.self::ROUND.'", "act": ["F2"]'))->succeeded());
        $this->append(LedgerEventKind::JudgeDone, $this->judgeDone(self::ROUND));

        self::assertSame(['F1', 'F3'], $this->reading()->actIdsOf(self::ROUND));
    }

    public function testAJudgeDoneRowWithAFindingOfAnUnknownSeverityIsSkipped(): void
    {
        $this->append(LedgerEventKind::JudgeDone, ['act' => ['F2'], 'findings' => [['id' => 'F2', 'severity' => 'P9', 'text' => 'Odd.']]] + $this->judgeDone(self::ROUND));

        self::assertSame([], $this->reading()->actIdsOf(self::ROUND));
    }

    /**
     * @return array<string, mixed>
     */
    private function judgeDone(string $round): array
    {
        return ['round' => $round, 'act' => ['F1', 'F3'], 'follow_ups' => ['F2'], 'findings' => [['id' => 'F1', 'severity' => 'P1', 'text' => 'Broken.'], ['id' => 'F2', 'severity' => 'P3', 'text' => 'Later.'], ['id' => 'F3', 'severity' => 'P2', 'text' => 'Untested.']]];
    }

    private function reading(): RoundLedgerReading
    {
        return new RoundLedgerReading($this->ledger, new LedgerPayloads(StoredSerializerFactory::create()), new ConfiguredPrices(['big' => ['input' => 2.0, 'output' => 10.0, 'cache_read' => 0.2, 'cache_write' => 2.5]]));
    }

    private function recordCall(string $call, ?string $round, string $ending): void
    {
        $started = ['call' => $call, 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard', 'model' => 'big'];
        $this->append(LedgerEventKind::UsageRecorded, null === $round ? $started : $started + ['round' => $round]);
        $ended = ['call' => $call, 'phase' => $ending, 'usages' => [$this->usage()]];
        $this->append(LedgerEventKind::UsageRecorded, 'unsettled' === $ending ? $ended + ['failure' => 'RuntimeException'] : $ended);
    }

    // Written through the payload classes from what the vendor's extractor read, because a row written by hand could carry a model the vendor never reports.
    private function recordAnswer(string $call, string $tierModel, ?TokenUsageInterface $usage): void
    {
        self::assertNotNull($usage);
        $row = new TokenUsageRow('claude_code.default', $usage->getModel(), $usage->getPromptTokens(), $usage->getCompletionTokens(), $usage->getThinkingTokens(), $usage->getToolTokens(), $usage->getCachedTokens(), $usage->getCacheCreationTokens(), $usage->getCacheReadTokens(), $usage->getTotalTokens(), $usage->getRemainingTokens(), $usage->getRemainingTokensMinute(), $usage->getRemainingTokensMonth());
        $serializer = StoredSerializerFactory::create();

        foreach ([new UsageStarted($call, 'started', 'alpha', Tier::Standard, self::ROUND, $tierModel), new UsageSettled($call, 'settled', [$row])] as $payload) {
            self::assertTrue($this->ledger->append(new LedgerEvent(LedgerEventKind::UsageRecorded, $serializer->serialize($payload, 'json')))->succeeded());
        }
    }

    /**
     * @return array<string, mixed>
     */
    // Every key UsageLedger writes, because a row is read back whole through its payload class.
    private function usage(): array
    {
        return ['account' => null, 'model' => 'big', 'prompt' => 100, 'completion' => 20, 'thinking' => null, 'tool' => null, 'cached' => null, 'cache_creation' => null, 'cache_read' => null, 'total' => 120, 'remaining' => null, 'remaining_minute' => null, 'remaining_month' => null];
    }

    /**
     * @return list<int>
     */
    private function tokensOf(string $round): array
    {
        $tokens = $this->reading()->tokensOf($round);

        return [$tokens->calls, $tokens->prompt, $tokens->completion, $tokens->total];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function append(LedgerEventKind $kind, array $payload): void
    {
        self::assertTrue($this->ledger->append(new LedgerEvent($kind, json_encode($payload, JSON_THROW_ON_ERROR)))->succeeded());
    }
}
