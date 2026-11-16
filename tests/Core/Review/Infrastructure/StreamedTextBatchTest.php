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

    public function testManyDeltasOfATurnArePublishedAsOnePieceWhenItIsReleased(): void
    {
        $batch = $this->batch('r1');

        for ($i = 0; $i < 100; ++$i) {
            $batch->add('x');
        }

        self::assertSame([], $this->updates->seen);

        $batch->release();

        self::assertSame(['text r1 '.str_repeat('x', 100)], $this->updates->seen);
    }

    public function testTextThatIsNeverReleasedIsNeverPublished(): void
    {
        $batch = $this->batch('r1');

        $batch->add('I read the cart.');
        $batch->release();
        $batch->add('{"verdict":"approve",');

        self::assertSame(['text r1 I read the cart.'], $this->updates->seen);
    }

    public function testEachReleasedTurnIsPublishedOnceAndInOrder(): void
    {
        $batch = $this->batch('r1');

        $batch->add('First.');
        $batch->release();
        $batch->release();
        $batch->add('Second.');
        $batch->release();

        self::assertSame(['text r1 First.', 'text r1 Second.'], $this->updates->seen);
    }

    public function testTwoReviewersAreBatchedSeparately(): void
    {
        $first = $this->batch('r1');
        $second = $this->batch('r2');

        $first->add('a');
        $second->add('b');
        $first->add('c');
        $second->add('d');
        $first->release();
        $second->release();

        self::assertSame(['text r1 ac', 'text r2 bd'], $this->updates->seen);
    }

    public function testATurnWithoutTextPublishesNothing(): void
    {
        $this->batch('r1')->release();

        self::assertSame([], $this->updates->seen);
    }

    private function batch(string $reviewer): StreamedTextBatch
    {
        return new StreamedTextBatch(fn (string $text) => $this->updates->textStreamed(Uuid::fromString(self::ROUND), $reviewer, $text));
    }
}
