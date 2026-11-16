<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Intakt\Core\Review\Infrastructure\StreamedTextBatch;
use Intakt\Tests\Core\Review\RecordedRoundUpdates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(StreamedTextBatch::class)]
final class StreamedTextBatchTest extends TestCase
{
    private const string ROUND = '0198a4a0-0000-7000-8000-000000000001';

    private RecordedRoundUpdates $updates;

    protected function setUp(): void
    {
        $this->updates = new RecordedRoundUpdates();
    }

    public function testTheDeltasOfATurnArePublishedTogetherWhenItsToolCallComes(): void
    {
        $batch = $this->batch('r1');

        $batch->add('Let me read');
        $batch->add(' the cart.');
        self::assertSame([], $this->updates->seen);

        $batch->nextTurn();

        self::assertSame(['text r1 Let me read the cart.'], $this->updates->seen);
    }

    public function testTwoReviewersAreBatchedSeparately(): void
    {
        $first = $this->batch('r1');
        $second = $this->batch('r2');

        $first->add('a');
        $second->add('b');
        $first->add('c');
        $second->add('d');
        $first->nextTurn();
        $second->nextTurn();

        self::assertSame(['text r1 ac', 'text r2 bd'], $this->updates->seen);
    }

    public function testATurnWithoutTextPublishesNothing(): void
    {
        $batch = $this->batch('r1');

        $batch->nextTurn();
        $batch->add(" \n");
        $batch->nextTurn();

        self::assertSame([], $this->updates->seen);
    }

    public function testAnAnswerInJsonIsNeverPublished(): void
    {
        $batch = $this->batch('r1');

        $batch->add('{"verdict":');
        $batch->add('"changes","findings":[]}');

        self::assertSame([], $this->updates->seen);
    }

    public function testAnAnswerWithProseBeforeItsJsonIsNeverPublished(): void
    {
        $batch = $this->batch('r1');

        $batch->add('Here is my review: ');
        $batch->add('{"verdict":"changes","findings":[]}');

        self::assertSame([], $this->updates->seen);
    }

    public function testAnAnswerWithProseBeforeItsFencedJsonIsNeverPublished(): void
    {
        $batch = $this->batch('r1');

        $batch->add("My verdict follows.\n");
        $batch->add("```json\n{\"verdict\":\"approve\",\"findings\":[],\"resolved\":[],\"follow_ups\":[]}\n```");

        self::assertSame([], $this->updates->seen);
    }

    public function testAnAnswerThatIsOnlyProseAndCannotBeReadIsNeverPublished(): void
    {
        $batch = $this->batch('r1');

        $batch->add('Looks good to me.');

        self::assertSame([], $this->updates->seen);
    }

    public function testProseKeepsABraceItWritesBeforeAToolCall(): void
    {
        $batch = $this->batch('r1');

        $batch->add('The array ');
        $batch->add('{a, b} is sorted.');
        $batch->nextTurn();

        self::assertSame(['text r1 The array {a, b} is sorted.'], $this->updates->seen);
    }

    public function testProseBeforeAToolCallIsPublishedAndTheAnswerAfterItIsNot(): void
    {
        $batch = $this->batch('r1');

        $batch->add('Let me read the cart.');
        $batch->nextTurn();
        $batch->add('Here is my review: {"verdict":"approve"}');

        self::assertSame(['text r1 Let me read the cart.'], $this->updates->seen);
    }

    private function batch(string $reviewer): StreamedTextBatch
    {
        return new StreamedTextBatch(fn (string $text) => $this->updates->textStreamed(Uuid::fromString(self::ROUND), $reviewer, $text));
    }
}
