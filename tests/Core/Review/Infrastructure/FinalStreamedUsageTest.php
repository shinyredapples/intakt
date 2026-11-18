<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Generator;
use Intakt\Core\Review\Infrastructure\FinalStreamedUsage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\TokenUsage\StreamListener;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

#[CoversClass(FinalStreamedUsage::class)]
final class FinalStreamedUsageTest extends TestCase
{
    public function testAStreamWithSeveralUsageEventsCarriesOnlyItsLastOne(): void
    {
        $final = new TokenUsage(
            promptTokens: 100,
            completionTokens: 20,
            totalTokens: 120,
        );
        $stream = $this->streamOf(new TokenUsage(promptTokens: 100), new TextDelta('{}'), $final);

        iterator_to_array($stream->getContent());

        self::assertSame([100, 20, null, null, 120], $this->figuresOf($stream));
    }

    public function testALaterUsageEventReplacesTheFiguresItReports(): void
    {
        $earlier = new TokenUsage(
            promptTokens: 90,
            completionTokens: 1,
        );
        $later = new TokenUsage(
            promptTokens: 100,
            completionTokens: 20,
        );
        $stream = $this->streamOf($earlier, $later);

        iterator_to_array($stream->getContent());

        self::assertSame([100, 20, null, null, null], $this->figuresOf($stream));
    }

    public function testAFigureALaterUsageEventLeavesOutKeepsItsEarlierReading(): void
    {
        $first = new TokenUsage(
            promptTokens: 150,
            cacheCreationTokens: 0,
            cacheReadTokens: 3000,
            model: 'claude-opus-5-5',
        );
        $last = new TokenUsage(
            completionTokens: 908,
            model: 'claude-opus-5-5',
        );
        $stream = $this->streamOf($first, new TextDelta('{}'), $last);

        iterator_to_array($stream->getContent());

        self::assertSame([150, 908, 0, 3000, null], $this->figuresOf($stream));
    }

    public function testAFigureNoUsageEventReportsStaysUnknown(): void
    {
        $stream = $this->streamOf(new TokenUsage(completionTokens: 1), new TokenUsage(completionTokens: 908));

        iterator_to_array($stream->getContent());

        self::assertSame([null, 908, null, null, null], $this->figuresOf($stream));
    }

    public function testAStreamCutOffCarriesTheUsageReportedBeforeTheCut(): void
    {
        $stream = $this->streamOf(new TokenUsage(promptTokens: 80), new TokenUsage(promptTokens: 90), new IncompleteStreamException('The stream ended early.'));

        try {
            iterator_to_array($stream->getContent());
            self::fail('A cut-off stream must stay an exception.');
        } catch (IncompleteStreamException) {
            // Expected, because the stream was cut off.
        }

        self::assertSame([90, null, null, null, null], $this->figuresOf($stream));
    }

    public function testAStreamWithoutUsageEventsCarriesNoUsage(): void
    {
        $stream = $this->streamOf(new TextDelta('{}'));

        iterator_to_array($stream->getContent());

        self::assertFalse($stream->getMetadata()->has('token_usage'));
    }

    // Followed by Symfony AI's usage listener, because a platform adds it after the converter's listeners.
    private function streamOf(TokenUsage|TextDelta|IncompleteStreamException ...$events): StreamResult
    {
        $deltas = (static function () use ($events): Generator {
            foreach ($events as $event) {
                if ($event instanceof IncompleteStreamException) {
                    throw $event;
                }

                yield $event;
            }
        })();

        return new StreamResult($deltas, [new FinalStreamedUsage(), new StreamListener()]);
    }

    /**
     * @return array{?int, ?int, ?int, ?int, ?int}
     */
    private function figuresOf(StreamResult $stream): array
    {
        $usage = $stream->getMetadata()->get('token_usage');
        self::assertInstanceOf(TokenUsageInterface::class, $usage);

        return [$usage->getPromptTokens(), $usage->getCompletionTokens(), $usage->getCacheCreationTokens(), $usage->getCacheReadTokens(), $usage->getTotalTokens()];
    }
}
