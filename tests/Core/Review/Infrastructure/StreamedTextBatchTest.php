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

    public function testATurnThatIsAJsonAnswerIsNeverPublishedEvenWhenReleased(): void
    {
        $this->released('{"verdict":"changes","findings":[{"id":"discount-boundary"}]}');

        self::assertSame([], $this->updates->seen);
    }

    public function testAJsonAnswerInAFenceAfterProseIsNeverPublished(): void
    {
        $this->released("Here is my verdict:\n```json\n{\"verdict\":\"approve\",\"findings\":[]}\n```");

        self::assertSame([], $this->updates->seen);
    }

    public function testProseWithBracesThatAreNoJsonIsStillPublished(): void
    {
        $this->released('I read match {case} in the cart next.');

        self::assertSame(['text r1 I read match {case} in the cart next.'], $this->updates->seen);
    }

    public function testAJsonAnswerFollowedByProseWithBracesIsNeverPublished(): void
    {
        $this->released("```json\n{\"verdict\":\"changes\",\"findings\":[{\"id\":\"F1\"}]}\n```\nLet me check match {case} once more.");

        self::assertSame([], $this->updates->seen);
    }

    public function testAJsonAnswerAfterProseWithBracesIsNeverPublished(): void
    {
        $this->released('I checked {case}. Verdict: {"verdict":"approve","findings":[]}');

        self::assertSame([], $this->updates->seen);
    }

    public function testTwoJsonObjectsInOneTurnAreNeverPublished(): void
    {
        $this->released("Draft: {\"verdict\":\"approve\",\"findings\":[]}\nFinal: {\"verdict\":\"changes\",\"findings\":[]}");

        self::assertSame([], $this->updates->seen);
    }

    public function testAnAlmostJsonAnswerIsNeverPublished(): void
    {
        $this->released('{"verdict":"changes","findings":[{"id":"F1","severity":"P1",}]}');

        self::assertSame([], $this->updates->seen);
    }

    public function testAJsonAnswerCutOffAfterItsFirstKeyIsNeverPublished(): void
    {
        $this->released("I am done.\n{ \"verd");

        self::assertSame([], $this->updates->seen);
    }

    public function testProseWithQuotesAndBracesThatOpenNoJsonObjectIsStillPublished(): void
    {
        $this->released('I read "match" in {case} and {} next.');

        self::assertSame(['text r1 I read "match" in {case} and {} next.'], $this->updates->seen);
    }

    public function testAJsonTurnIsDroppedAndTheNextTurnIsStillPublished(): void
    {
        $batch = $this->batch('r1');

        $batch->add('{"verdict":"approve"}');
        $batch->release();
        $batch->add('I read the cart.');
        $batch->release();

        self::assertSame(['text r1 I read the cart.'], $this->updates->seen);
    }

    public function testATurnWithoutTextPublishesNothing(): void
    {
        $this->batch('r1')->release();

        self::assertSame([], $this->updates->seen);
    }

    protected function setUp(): void
    {
        $this->updates = new RecordedRoundUpdates();
    }

    private function released(string $turn): void
    {
        $batch = $this->batch('r1');
        $batch->add($turn);
        $batch->release();
    }

    private function batch(string $reviewer): StreamedTextBatch
    {
        return new StreamedTextBatch(fn (string $text) => $this->updates->textStreamed(Uuid::fromString(self::ROUND), $reviewer, $text));
    }
}
