<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Generator;
use Intakt\Core\Review\Infrastructure\FinalStreamedUsage;
use Intakt\Plugin\SymfonyAiVendor\Contract\TokenUsageRow;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageSettled;
use Intakt\Plugin\SymfonyAiVendor\Contract\UsageUnsettled;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageReservation;
use Intakt\Tests\Support\StoredSerializerFactory;
use JsonException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\StreamListener;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
use Throwable;

#[CoversClass(UsageLedger::class)]
#[UsesClass(FinalStreamedUsage::class)]
#[UsesClass(UsageReservation::class)]
#[UsesClass(UsageSettled::class)]
#[UsesClass(UsageUnsettled::class)]
#[UsesClass(TokenUsageRow::class)]
final class UsageLedgerTest extends TestCase
{
    public function testACallWhoseAnswerIsAStreamSettlesWithOnlyItsFinalUsageOnce(): void
    {
        $ledger = new InMemoryLedger();
        $round = $this->roundAnswering($stream = $this->streamWithSeveralUsages());
        iterator_to_array($stream->getContent());

        $this->settle($ledger, $round);

        /** @var list<array<string, int>> $usages */
        $usages = $ledger->payloads()[0]['usages'];
        self::assertCount(1, $usages);
        self::assertSame([100, 20, 120], [$usages[0]['prompt'], $usages[0]['completion'], $usages[0]['total']]);
    }

    public function testTheSameAnswerUnstreamedSettlesWithTheSameUsageRow(): void
    {
        $streamed = new InMemoryLedger();
        $round = $this->roundAnswering($stream = $this->streamWithSeveralUsages());
        iterator_to_array($stream->getContent());
        $this->settle($streamed, $round);
        $unstreamed = new InMemoryLedger();
        $text = new TextResult('{}');
        $text->getMetadata()->add('token_usage', new TokenUsage(
            promptTokens: 100,
            completionTokens: 20,
            totalTokens: 120,
        ));

        $this->settle($unstreamed, $this->roundAnswering($text));

        self::assertSame($streamed->payloads()[0]['usages'], $unstreamed->payloads()[0]['usages']);
    }

    public function testAProgrammingErrorWhileReadingAStreamedResultIsNotReadAsNoUsage(): void
    {
        $ledger = new InMemoryLedger();
        $converter = self::createStub(ResultConverterInterface::class);
        $converter->method('convert')->willThrowException(new LogicException('a bug'));

        $this->settle($ledger, new DeferredResult($converter, new InMemoryRawResult()));

        self::assertSame(['unsettled', LogicException::class], [$ledger->payloads()[0]['phase'], $ledger->payloads()[0]['failure']]);
    }

    public function testALibraryFailureWhileReadingAStreamedResultFallsBackToTheRecordedUsage(): void
    {
        $ledger = new InMemoryLedger();
        $converter = self::createStub(ResultConverterInterface::class);
        $converter->method('convert')->willThrowException(new RuntimeException('cannot convert'));

        $this->settle($ledger, new DeferredResult($converter, new InMemoryRawResult()));

        self::assertSame('settled', $ledger->payloads()[0]['phase']);
    }

    public function testAnHttpClientFailureWhileReadingAStreamedResultFallsBackToTheRecordedUsage(): void
    {
        $ledger = new InMemoryLedger();
        $converter = self::createStub(ResultConverterInterface::class);
        $converter->method('convert')->willThrowException(new TransportException('connection lost'));
        $converter->method('getTokenUsageExtractor')->willReturn($this->extractorReading(new TokenUsage(promptTokens: 7)));

        $this->settle($ledger, new DeferredResult($converter, new InMemoryRawResult()));

        self::assertSame(['settled', [7]], [$ledger->payloads()[0]['phase'], $this->promptTokensOf($ledger)]);
    }

    public function testARoundWhoseUsageExtractionFailsLeavesTheCallUnsettledWithItsFailureClass(): void
    {
        $ledger = new InMemoryLedger();
        $reads = 0;

        $this->usageLedger($ledger)->settle(new UsageReservation('a call'), $this->roundWithUnreadableUsage($reads), $this->roundAnswering($this->textWithUsage(42)));

        self::assertSame(['unsettled', JsonException::class], [$ledger->payloads()[0]['phase'], $ledger->payloads()[0]['failure']]);
    }

    public function testALaterRoundKeepsItsUsageWhenAnEarlierRoundIsUnreadable(): void
    {
        $ledger = new InMemoryLedger();
        $reads = 0;

        $this->usageLedger($ledger)->settle(new UsageReservation('a call'), $this->roundWithUnreadableUsage($reads), $this->roundAnswering($this->textWithUsage(42)));

        self::assertSame([42], $this->promptTokensOf($ledger));
    }

    public function testARoundWhoseUsageExtractionFailsIsReadOnce(): void
    {
        $reads = 0;

        $this->usageLedger(new InMemoryLedger())->settle(new UsageReservation('a call'), $this->roundWithUnreadableUsage($reads), $this->roundAnswering($this->textWithUsage(42)));

        self::assertSame(1, $reads);
    }

    public function testAnUnreadableRoundLogsOneWarningWhenTheCallIsSettled(): void
    {
        $reads = 0;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $usage = new UsageLedger(new InMemoryLedger(), new MockClock('2026-01-01T00:00:00+00:00'), $logger, StoredSerializerFactory::create());

        $usage->settle(new UsageReservation('a call'), $this->roundWithUnreadableUsage($reads), $this->roundAnswering($this->textWithUsage(42)));
    }

    public function testAFailedCallLeftUnsettledKeepsItsOwnFailureClassWhenARoundIsUnreadable(): void
    {
        $ledger = new InMemoryLedger();
        $reads = 0;

        $this->usageLedger($ledger)->leaveUnsettled(new UsageReservation('a call'), new LogicException('the call failed'), $this->roundWithUnreadableUsage($reads), $this->roundAnswering($this->textWithUsage(42)));

        self::assertSame([LogicException::class, [42], 1], [$ledger->payloads()[0]['failure'], $this->promptTokensOf($ledger), $reads]);
    }

    public function testAnAnsweredRoundKeepsItsUsageWhenALaterRoundOfAFailedCallFailsWithANonLibraryException(): void
    {
        $ledger = new InMemoryLedger();
        $failure = new JsonException('Syntax error in tool call arguments');

        $this->usageLedger($ledger)->leaveUnsettled(new UsageReservation('a call'), $failure, $this->roundAnswering($this->textWithUsage(100)), $this->roundFailingWith($failure));

        self::assertSame([100], $this->promptTokensOf($ledger));
    }

    public function testAnAnsweredRoundKeepsItsUsageWhenAnEarlierRoundOfAFailedCallFailsWithANonLibraryException(): void
    {
        $ledger = new InMemoryLedger();
        $failure = new JsonException('Syntax error in tool call arguments');

        $this->usageLedger($ledger)->leaveUnsettled(new UsageReservation('a call'), $failure, $this->roundFailingWith($failure), $this->roundAnswering($this->textWithUsage(200)));

        self::assertSame([200], $this->promptTokensOf($ledger));
    }

    public function testAnAnsweredCallWithOneUnreadableRoundIsUnsettledAndKeepsTheUsageOfItsOtherRounds(): void
    {
        $ledger = new InMemoryLedger();

        $this->usageLedger($ledger)->settle(new UsageReservation('a call'), $this->roundAnswering($this->textWithUsage(100)), $this->roundFailingWith(new LogicException('a bug')), $this->roundAnswering($this->textWithUsage(300)));

        self::assertSame(['unsettled', LogicException::class, [100, 300]], [$ledger->payloads()[0]['phase'], $ledger->payloads()[0]['failure'], $this->promptTokensOf($ledger)]);
    }

    private function usageLedger(InMemoryLedger $ledger): UsageLedger
    {
        return new UsageLedger($ledger, new MockClock('2026-01-01T00:00:00+00:00'), new NullLogger(), StoredSerializerFactory::create());
    }

    private function roundFailingWith(Throwable $failure): DeferredResult
    {
        $converter = self::createStub(ResultConverterInterface::class);
        $converter->method('convert')->willThrowException($failure);

        return new DeferredResult($converter, new InMemoryRawResult());
    }

    // Counts the reads, because a settlement that scanned the rounds twice ran a failing extractor twice (issue #2564).
    private function roundWithUnreadableUsage(int &$reads): DeferredResult
    {
        $extractor = self::createStub(TokenUsageExtractorInterface::class);
        $extractor->method('extract')->willReturnCallback(static function () use (&$reads): never {
            ++$reads;

            throw new JsonException('usage decode failed');
        });
        $converter = self::createStub(ResultConverterInterface::class);
        $converter->method('convert')->willThrowException(new RuntimeException('cannot map answer'));
        $converter->method('getTokenUsageExtractor')->willReturn($extractor);

        return new DeferredResult($converter, new InMemoryRawResult());
    }

    private function textWithUsage(int $promptTokens): TextResult
    {
        $text = new TextResult('{}');
        $text->getMetadata()->add('token_usage', new TokenUsage(
            promptTokens: $promptTokens,
            completionTokens: 20,
            totalTokens: $promptTokens + 20,
        ));

        return $text;
    }

    private function extractorReading(TokenUsage $usage): TokenUsageExtractorInterface
    {
        $extractor = self::createStub(TokenUsageExtractorInterface::class);
        $extractor->method('extract')->willReturn($usage);

        return $extractor;
    }

    /**
     * @return list<int>
     */
    private function promptTokensOf(InMemoryLedger $ledger): array
    {
        /** @var list<array<string, int>> $usages */
        $usages = $ledger->payloads()[0]['usages'];

        return array_column($usages, 'prompt');
    }

    // Followed by Symfony AI's usage listener, because a platform adds it after the converter's listeners.
    private function streamWithSeveralUsages(): StreamResult
    {
        $deltas = (static function (): Generator {
            yield new TokenUsage(promptTokens: 100);
            yield new TextDelta('{}');
            yield new TokenUsage(
                promptTokens: 100,
                completionTokens: 20,
                totalTokens: 120,
            );
        })();

        return new StreamResult($deltas, [new FinalStreamedUsage(), new StreamListener()]);
    }

    private function roundAnswering(ResultInterface $answer): DeferredResult
    {
        $converter = self::createStub(ResultConverterInterface::class);
        $converter->method('convert')->willReturn($answer);

        return new DeferredResult($converter, new InMemoryRawResult());
    }

    private function settle(InMemoryLedger $ledger, DeferredResult $round): void
    {
        $this->usageLedger($ledger)->settle(new UsageReservation('a call'), $round);
    }
}
