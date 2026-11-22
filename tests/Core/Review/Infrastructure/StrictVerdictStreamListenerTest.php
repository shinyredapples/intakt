<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Generator;
use Intakt\Core\Review\Contract\Decision;
use Intakt\Core\Review\Contract\StreamChunks;
use Intakt\Core\Review\Contract\StreamCutBy;
use Intakt\Core\Review\Contract\StreamEndedEarly;
use Intakt\Core\Review\Contract\Verdict;
use Intakt\Core\Review\Infrastructure\StreamTally;
use Intakt\Core\Review\Infrastructure\StrictVerdictStreamListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Result\Stream\CompleteEvent;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\TokenUsage\TokenUsage;

#[CoversClass(StrictVerdictStreamListener::class)]
#[CoversClass(StreamTally::class)]
#[CoversClass(StreamEndedEarly::class)]
#[UsesClass(Verdict::class)]
#[UsesClass(StreamChunks::class)]
final class StrictVerdictStreamListenerTest extends TestCase
{
    private StreamTally $tally;

    /**
     * @var list<string>
     */
    private array $read = [];

    protected function setUp(): void
    {
        $this->tally = new StreamTally();
    }

    public function testAStreamThatGaveOnlyThinkingIsCutAsAnUnfinishedAnswerWithTheChunksItCounted(): void
    {
        $cut = $this->completedAfter(new TokenUsage(promptTokens: 100), new ThinkingDelta('The diff adds a cart.'));

        self::assertInstanceOf(StreamEndedEarly::class, $cut);
        self::assertSame('The reviewer stream ended before its answer was complete.', $cut->getMessage());
        self::assertSame([StreamCutBy::UnfinishedAnswer, 2], [$cut->by, $cut->chunks]);
        self::assertSame(['TokenUsage', 'ThinkingDelta "The diff adds a cart."'], [...$cut->lastChunks]);
    }

    public function testAStreamThatGaveAWholeAnswerIsNotCut(): void
    {
        $answer = '{"verdict":"approve","findings":[],"resolved":[],"follow_ups":[]}';

        self::assertNull($this->completedAfter(new TextDelta($answer)));
        self::assertSame([$answer], $this->read);
    }

    private function completedAfter(TokenUsage|ThinkingDelta|TextDelta ...$deltas): ?StreamEndedEarly
    {
        $listener = new StrictVerdictStreamListener($this->verdictFrom(...), $this->tally);
        $stream = new StreamResult($this->none());

        foreach ($deltas as $delta) {
            $this->tally->counted($delta);
            $listener->onDelta(new DeltaEvent($stream, $delta));
        }

        try {
            $listener->onComplete(new CompleteEvent($stream));
        } catch (StreamEndedEarly $cut) {
            return $cut;
        }

        return null;
    }

    private function verdictFrom(string $answer): Verdict
    {
        $this->read[] = $answer;

        return new Verdict(Decision::Approve, [], [], []);
    }

    private function none(): Generator
    {
        yield from [];
    }
}
