<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Ai\Contract\Account;
use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Ledger\Contract\AppendResult;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageReservation;
use Intakt\Plugin\Vendor\Contract\UsageNotRecorded;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use JsonException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageAggregation;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\Uid\UuidV7;

#[CoversClass(UsageLedger::class)]
#[UsesClass(UsageReservation::class)]
#[UsesClass(UsageNotRecorded::class)]
#[UsesClass(LedgerEvent::class)]
#[UsesClass(LedgerEventKind::class)]
#[UsesClass(AppendResult::class)]
#[UsesClass(Tier::class)]
final class UsageLedgerTest extends TestCase
{
    private const string NOW = '2026-10-27 12:00:00.000 UTC';

    private InMemoryLedger $ledger;

    private BufferingLogger $logger;

    protected function setUp(): void
    {
        $this->ledger = new InMemoryLedger();
        $this->logger = new BufferingLogger();
    }

    public function testAReservationIsRecordedAsAStartedCallOfItsProjectAndTier(): void
    {
        $reservation = $this->usage()->reserve('alpha', Tier::Strong);

        self::assertEquals([LedgerEventKind::UsageRecorded], $this->ledger->kinds());
        self::assertSame(['call' => $reservation->call, 'phase' => 'started', 'project' => 'alpha', 'tier' => 'strong'], $this->ledger->payloads()[0]);
        self::assertSame([], $this->logger->cleanLogs());
    }

    public function testTheIdOfAReservedCallTakesItsMomentFromTheClock(): void
    {
        $reservation = $this->usage()->reserve('alpha', Tier::Strong);

        self::assertEquals(new MockClock(self::NOW)->now(), UuidV7::fromString($reservation->call)->getDateTime());
    }

    public function testASettledCallRecordsTheTokensOfEveryRound(): void
    {
        $this->usage()->settle(new UsageReservation('a call'), $this->round(new TokenUsage(promptTokens: 7, model: 'big')), $this->round(new TokenUsage(promptTokens: 3, model: 'big')));

        self::assertSame(['a call', 'settled'], [$this->ledger->payloads()[0]['call'], $this->ledger->payloads()[0]['phase']]);
        self::assertSame([7, 3], array_column($this->usages(0), 'prompt'));
    }

    public function testAnAggregatedUsageIsRecordedAsOneRowPerPart(): void
    {
        $this->usage()->settle(new UsageReservation('a call'), $this->round(new TokenUsageAggregation([new TokenUsage(promptTokens: 7, model: 'big'), new TokenUsage(promptTokens: 3, model: 'small')])));

        self::assertSame(['big', 'small'], array_column($this->usages(0), 'model'));
    }

    public function testARoundWithoutUsageAddsNoRow(): void
    {
        $this->usage()->settle(new UsageReservation('a call'), $this->round(null));

        self::assertSame([], $this->usages(0));
    }

    public function testAUsageRowNamesTheAccountItsRoundRanOn(): void
    {
        $round = $this->round(new TokenUsage(promptTokens: 7, model: 'big'));
        $round->getMetadata()->add(Account::METADATA, 'second');

        $this->usage()->settle(new UsageReservation('a call'), $round);

        self::assertSame('second', $this->usages(0)[0]['account']);
    }

    public function testASettlementWhoseUsageCannotBeReadIsLeftUnsettledAndLogged(): void
    {
        $this->usage()->settle(new UsageReservation('a call'), $this->unreadableRound());

        self::assertSame(['unsettled', JsonException::class], [$this->ledger->payloads()[0]['phase'], $this->ledger->payloads()[0]['failure']]);
        self::assertSame('warning', $this->logger->cleanLogs()[0][0]);
    }

    public function testAFailedCallIsLeftUnsettledWithItsFailureAndTheTokensOfTheRoundsBefore(): void
    {
        $this->usage()->leaveUnsettled(new UsageReservation('a call'), new RuntimeException('provider down'), $this->round(new TokenUsage(promptTokens: 7, model: 'big')));

        self::assertSame(['unsettled', RuntimeException::class], [$this->ledger->payloads()[0]['phase'], $this->ledger->payloads()[0]['failure']]);
        self::assertSame([7], array_column($this->usages(0), 'prompt'));
    }

    public function testARowTheLedgerRefusesIsLoggedAndFailsWithTheCallFailureAsItsCause(): void
    {
        $failure = new RuntimeException('provider down');
        $this->ledger = new InMemoryLedger(accepted: 0);

        try {
            $this->usage()->leaveUnsettled(new UsageReservation('a call'), $failure);
            self::fail('A row the ledger refused must not pass as recorded.');
        } catch (UsageNotRecorded $refused) {
            self::assertSame($failure, $refused->getPrevious());
            self::assertStringContainsString('the ledger is down', $refused->getMessage());
        }

        self::assertSame('error', $this->logger->cleanLogs()[0][0]);
    }

    public function testARoundWhoseUsageCannotBeReadBeforeAFailureIsLoggedAndTheFailureStillRecorded(): void
    {
        $extractor = self::createStub(TokenUsageExtractorInterface::class);
        $extractor->method('extract')->willThrowException(new JsonException('Syntax error'));
        $converter = self::createStub(ResultConverterInterface::class);
        $converter->method('getTokenUsageExtractor')->willReturn($extractor);
        $ledger = new InMemoryLedger();
        $logger = new BufferingLogger();

        new UsageLedger($ledger, new MockClock(self::NOW), $logger)->leaveUnsettled(new UsageReservation('a call'), new ServerException(503), new DeferredResult($converter, new InMemoryRawResult()));

        self::assertSame(['unsettled', ServerException::class, []], [$ledger->payloads()[0]['phase'], $ledger->payloads()[0]['failure'], $ledger->payloads()[0]['usages']]);
        /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> $logs */
        $logs = $logger->cleanLogs();
        self::assertSame('warning', $logs[0][0]);
        self::assertInstanceOf(JsonException::class, $logs[0][2]['exception']);
    }

    public function testAProviderAnswerThatFailsToMapSettlesWithTheTokensItsExtractorReads(): void
    {
        $extractor = self::createStub(TokenUsageExtractorInterface::class);
        $extractor->method('extract')->willReturn(new TokenUsage(promptTokens: 9, model: 'big'));
        $converter = self::createStub(ResultConverterInterface::class);
        $converter->method('convert')->willThrowException(new RuntimeException('not a verdict'));
        $converter->method('getTokenUsageExtractor')->willReturn($extractor);
        $result = new DeferredResult($converter, new InMemoryRawResult());
        $ledger = new InMemoryLedger();

        new UsageLedger($ledger, new MockClock(self::NOW), new NullLogger())->settle(new UsageReservation('a call'), $result);

        /** @var list<array<string, int>> $usages */
        $usages = $ledger->payloads()[0]['usages'];
        self::assertSame(9, $usages[0]['prompt']);
    }

    private function usage(): UsageLedger
    {
        return new UsageLedger($this->ledger, new MockClock(self::NOW), $this->logger);
    }

    private function round(?TokenUsageInterface $usage): DeferredResult
    {
        $round = new DeferredResult(self::createStub(ResultConverterInterface::class), new InMemoryRawResult());
        if (null !== $usage) {
            $round->getMetadata()->add('token_usage', $usage);
        }

        return $round;
    }

    private function unreadableRound(): DeferredResult
    {
        $extractor = self::createStub(TokenUsageExtractorInterface::class);
        $extractor->method('extract')->willThrowException(new JsonException('Syntax error'));
        $converter = self::createStub(ResultConverterInterface::class);
        $converter->method('getTokenUsageExtractor')->willReturn($extractor);

        return new DeferredResult($converter, new InMemoryRawResult());
    }

    /**
     * @return list<array<string, int|string|null>>
     */
    private function usages(int $row): array
    {
        /** @var list<array<string, int|string|null>> $usages */
        $usages = $this->ledger->payloads()[$row]['usages'];

        return $usages;
    }
}
