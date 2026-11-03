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

#[CoversClass(FinalStreamedUsage::class)]
final class FinalStreamedUsageTest extends TestCase
{
    public function testAStreamWithSeveralUsageEventsCarriesOnlyItsLastOne(): void
    {
        $final = new TokenUsage(promptTokens: 100, completionTokens: 20, totalTokens: 120);
        $stream = $this->streamOf(new TokenUsage(promptTokens: 100), new TextDelta('{}'), $final);

        iterator_to_array($stream->getContent());

        self::assertSame($final, $stream->getMetadata()->get('token_usage'));
    }

    public function testAStreamCutOffCarriesTheLastUsageEventBeforeTheCut(): void
    {
        $before = new TokenUsage(promptTokens: 90);
        $stream = $this->streamOf(new TokenUsage(promptTokens: 80), $before, new IncompleteStreamException('The stream ended early.'));

        try {
            iterator_to_array($stream->getContent());
            self::fail('A cut-off stream must stay an exception.');
        } catch (IncompleteStreamException) {
            // Expected, because the stream was cut off.
        }

        self::assertSame($before, $stream->getMetadata()->get('token_usage'));
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
}
