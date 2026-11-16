<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Infrastructure\StreamedTextBatch;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Uid\Uuid;

#[CoversClass(StreamedTextBatch::class)]
final class StreamedTextBatchTest extends TestCase
{
    private const string ROUND = '0198a4a0-0000-7000-8000-000000000001';

    private RecordedRoundUpdates $updates;

    private MockClock $clock;

    protected function setUp(): void
    {
        $this->updates = new RecordedRoundUpdates();
        $this->clock = new MockClock('2026-01-01 00:00:00');
    }

    public function testManyDeltasInOneIntervalPublishTheFirstAndThenTheRestWhenTheAnswerEnds(): void
    {
        $batch = $this->batch('r1');

        for ($i = 0; $i < 100; ++$i) {
            $batch->add('x');
        }

        self::assertSame(['text r1 x'], $this->updates->seen);

        $batch->flush();

        self::assertSame(['text r1 x', 'text r1 '.str_repeat('x', 99)], $this->updates->seen);
    }

    public function testDeltasSpreadOverTimePublishAtMostOncePerInterval(): void
    {
        $batch = $this->batch('r1');
        $answer = '';

        for ($i = 0; $i < 200; ++$i) {
            $batch->add('d');
            $answer .= 'd';
            $this->clock->sleep(0.01);
        }

        $batch->flush();

        $secondsOfDeltas = 2;
        $publishesPerSecond = 4;
        self::assertLessThanOrEqual($secondsOfDeltas * $publishesPerSecond + 2, count($this->updates->seen));
        self::assertSame($answer, $this->publishedText('r1'));
    }

    public function testTwoReviewersAreBatchedSeparately(): void
    {
        $first = $this->batch('r1');
        $second = $this->batch('r2');

        $first->add('a');
        $second->add('b');
        $first->add('c');
        $second->add('d');
        $first->flush();
        $second->flush();

        self::assertSame('ac', $this->publishedText('r1'));
        self::assertSame('bd', $this->publishedText('r2'));
    }

    public function testAnAnswerWithoutTextPublishesNothing(): void
    {
        $this->batch('r1')->flush();

        self::assertSame([], $this->updates->seen);
    }

    public function testATurnThatOpensWithAJsonDocumentIsNeverPublished(): void
    {
        $batch = $this->batch('r1');

        $batch->add(" \n");
        $batch->add('{"verdict":');
        $this->clock->sleep(1);
        $batch->add('"changes"}');
        $batch->flush();

        self::assertSame([], $this->updates->seen);
    }

    public function testATurnThatOpensWithAFencedBlockIsNeverPublished(): void
    {
        $batch = $this->batch('r1');

        $batch->add('`');
        $batch->add("``json\n{\"verdict\":\"approve\"}\n```");
        $batch->flush();

        self::assertSame([], $this->updates->seen);
    }

    public function testBlankTextWaitsUntilTheTurnShowsItIsProseAndIsThenPublishedWithIt(): void
    {
        $batch = $this->batch('r1');

        $batch->add("\n");
        self::assertSame([], $this->updates->seen);

        $batch->add('Looking at the cart.');

        self::assertSame(["text r1 \nLooking at the cart."], $this->updates->seen);
    }

    public function testProseKeepsPublishingABraceItWritesLaterInTheSameTurn(): void
    {
        $batch = $this->batch('r1');

        $batch->add('The array ');
        $batch->add('{a, b} is sorted.');
        $batch->flush();

        self::assertSame('The array {a, b} is sorted.', $this->publishedText('r1'));
    }

    public function testProseBeforeAToolCallIsPublishedAndTheAnswerAfterItIsHeldBack(): void
    {
        $batch = $this->batch('r1');

        $batch->add('Let me read');
        $batch->add(' the cart.');
        $batch->nextTurn();
        $batch->add('{"verdict":"approve"}');
        $batch->flush();

        self::assertSame('Let me read the cart.', $this->publishedText('r1'));
    }

    public function testAnAnswerHeldBackDoesNotHoldBackTheProseOfTheTurnAfterIt(): void
    {
        $batch = $this->batch('r1');

        $batch->add('{"note":');
        $batch->nextTurn();
        $batch->add('Reading the tests now.');
        $batch->flush();

        self::assertSame('Reading the tests now.', $this->publishedText('r1'));
    }

    private function batch(string $reviewer): StreamedTextBatch
    {
        return new StreamedTextBatch(fn (string $text) => $this->updates->textStreamed(Uuid::fromString(self::ROUND), $reviewer, $text), $this->clock);
    }

    private function publishedText(string $reviewer): string
    {
        $prefix = 'text '.$reviewer.' ';
        $texts = array_map(static fn (string $line): string => substr($line, strlen($prefix)), array_filter($this->updates->seen, static fn (string $line): bool => str_starts_with($line, $prefix)));

        return implode('', $texts);
    }
}
