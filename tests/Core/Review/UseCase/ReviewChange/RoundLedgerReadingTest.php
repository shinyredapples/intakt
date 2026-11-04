<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Intakt\Tests\Support\KernelTestCase;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;

#[Group('persistence')]
#[CoversClass(RoundLedgerReading::class)]
#[UsesClass(RoundTokens::class)]
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

    private function recordCall(string $call, ?string $round, string $ending): void
    {
        $started = ['call' => $call, 'phase' => 'started', 'project' => 'alpha', 'tier' => 'standard'];
        $this->append(LedgerEventKind::UsageRecorded, null === $round ? $started : $started + ['round' => $round]);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => $call, 'phase' => $ending, 'usages' => [['account' => null, 'prompt' => 100, 'completion' => 20, 'total' => 120]]]);
    }

    /**
     * @return list<int>
     */
    private function tokensOf(string $round): array
    {
        $tokens = new RoundLedgerReading($this->ledger)->tokensOf($round);

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
