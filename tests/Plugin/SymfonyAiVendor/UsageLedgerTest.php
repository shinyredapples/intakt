<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\SymfonyAiVendor;

use Intakt\Tests\Support\StoredSerializerFactory;
use Generator;
use Intakt\Core\Review\Infrastructure\FinalStreamedUsage;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageLedger;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\UsageReservation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\StreamListener;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\Component\Clock\MockClock;

#[CoversClass(UsageLedger::class)]
#[UsesClass(FinalStreamedUsage::class)]
#[UsesClass(UsageReservation::class)]
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
        $text->getMetadata()->add('token_usage', new TokenUsage(promptTokens: 100, completionTokens: 20, totalTokens: 120));

        $this->settle($unstreamed, $this->roundAnswering($text));

        self::assertSame($streamed->payloads()[0]['usages'], $unstreamed->payloads()[0]['usages']);
    }

    private function settle(InMemoryLedger $ledger, DeferredResult $round): void
    {
        new UsageLedger($ledger, new MockClock('2026-01-01T00:00:00+00:00'), new NullLogger(), StoredSerializerFactory::create())->settle(new UsageReservation('a call'), $round);
    }

    private function roundAnswering(ResultInterface $answer): DeferredResult
    {
        $converter = self::createStub(ResultConverterInterface::class);
        $converter->method('convert')->willReturn($answer);

        return new DeferredResult($converter, new InMemoryRawResult());
    }

    // Followed by Symfony AI's usage listener, because a platform adds it after the converter's listeners.
    private function streamWithSeveralUsages(): StreamResult
    {
        $deltas = (static function (): Generator {
            yield new TokenUsage(promptTokens: 100);
            yield new TextDelta('{}');
            yield new TokenUsage(promptTokens: 100, completionTokens: 20, totalTokens: 120);
        })();

        return new StreamResult($deltas, [new FinalStreamedUsage(), new StreamListener()]);
    }
}
