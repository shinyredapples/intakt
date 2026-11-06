<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\LedgerPayloads;
use Intakt\Core\Review\Contract\JudgeDone;
use Intakt\Core\Review\Contract\JudgeDoneFinding;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
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

#[Group('persistence')]
#[CoversClass(RoundLedgerReading::class)]
#[CoversClass(LedgerPayloads::class)]
#[UsesClass(RoundTokens::class)]
#[UsesClass(JudgeDone::class)]
#[UsesClass(JudgeDoneFinding::class)]
#[UsesClass(UsageStarted::class)]
#[UsesClass(UsageSettled::class)]
#[UsesClass(UsageUnsettled::class)]
#[UsesClass(TokenUsageRow::class)]
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
        return new RoundLedgerReading($this->ledger, new LedgerPayloads(StoredSerializerFactory::create()));
    }

    private function recordCall(string $call, ?string $round, string $ending): void
    {
        $started = ['call' => $call, 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard'];
        $this->append(LedgerEventKind::UsageRecorded, null === $round ? $started : $started + ['round' => $round]);
        $ended = ['call' => $call, 'phase' => $ending, 'usages' => [$this->usage()]];
        $this->append(LedgerEventKind::UsageRecorded, 'unsettled' === $ending ? $ended + ['failure' => 'RuntimeException'] : $ended);
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
