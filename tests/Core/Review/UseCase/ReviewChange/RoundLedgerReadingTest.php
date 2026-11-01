<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Review\Contract\RoundTokens;
use Intakt\Core\Review\UseCase\ReviewChange\RoundLedgerReading;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\UsesClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

#[Group('persistence')]
#[CoversClass(RoundLedgerReading::class)]
#[UsesClass(RoundTokens::class)]
final class RoundLedgerReadingTest extends KernelTestCase
{
    private const string ROUND = '0192a000-0000-7000-8000-000000000001';

    private Ledger $ledger;

    protected function setUp(): void
    {
        $this->ledger = new RoundFixture(self::getContainer())->ledger();
    }

    public function testTheTokensOfACallRecordedTheWayUsageLedgerRecordsItAreCounted(): void
    {
        $this->roundStarts();
        $this->recordCall('c1', 'alpha', 'settled');

        self::assertSame([1, 100, 20, 120], $this->tokensOf('alpha'));
    }

    public function testTheTokensAnUnsettledCallSpentAreCounted(): void
    {
        $this->roundStarts();
        $this->recordCall('c1', 'alpha', 'unsettled');

        self::assertSame([1, 100, 20, 120], $this->tokensOf('alpha'));
    }

    public function testACallOfAnotherProjectIsLeftOut(): void
    {
        $this->roundStarts();
        $this->recordCall('c1', 'beta', 'settled');

        self::assertSame([0, 0, 0, 0], $this->tokensOf('alpha'));
    }

    public function testACallStartedBeforeTheRoundIsLeftOut(): void
    {
        $this->recordCall('c0', 'alpha', 'settled');
        $this->roundStarts();

        self::assertSame([0, 0, 0, 0], $this->tokensOf('alpha'));
    }

    private function roundStarts(): void
    {
        $this->append(LedgerEventKind::ReviewStarted, ['round' => self::ROUND, 'project' => 'alpha', 'reviewers' => 1]);
    }

    private function recordCall(string $call, string $project, string $ending): void
    {
        $this->append(LedgerEventKind::UsageRecorded, ['call' => $call, 'phase' => 'started', 'project' => $project, 'tier' => 'standard']);
        $this->append(LedgerEventKind::UsageRecorded, ['call' => $call, 'phase' => $ending, 'usages' => [['account' => null, 'prompt' => 100, 'completion' => 20, 'total' => 120]]]);
    }

    /**
     * @return list<int>
     */
    private function tokensOf(string $project): array
    {
        $tokens = new RoundLedgerReading($this->ledger)->tokensSince(self::ROUND, $project);

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
