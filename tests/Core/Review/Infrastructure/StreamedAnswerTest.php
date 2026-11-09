<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Generator;
use Intakt\Core\Review\Infrastructure\AnthropicTextBlocks;
use Intakt\Core\Review\Infrastructure\StreamedAnswer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\Stream\AbstractStreamListener;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;
use Symfony\AI\Platform\Result\StreamResult;

#[CoversClass(StreamedAnswer::class)]
#[CoversClass(AnthropicTextBlocks::class)]
final class StreamedAnswerTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $seenAfter = [];

    public function testTheTextStillStreamsPieceByPieceAndOnlyOnce(): void
    {
        $emitted = $this->streamed([$this->textBlock(), $this->text('{"verdict":'), $this->text('"approve"}')]);

        self::assertSame(['{"verdict":', '"approve"}'], $emitted);
    }

    public function testTheListenersAfterItSeeTheWholeAnswerOnceWhenTheRoundEnds(): void
    {
        $this->streamed([$this->textBlock(), $this->text('{"verdict":'), $this->text('"approve"}')]);

        self::assertSame(['{"verdict":"approve"}'], $this->seenAfter);
    }

    public function testTheListenersAfterItSeeNoTextOfARoundThatAsksForTools(): void
    {
        $emitted = $this->streamed([$this->textBlock(), $this->text('I will read the cart.'), $this->toolUse()]);

        self::assertSame([], $this->seenAfter);
        self::assertSame(['I will read the cart.'], $emitted);
    }

    public function testAnAnswerSplitAcrossTwoTextBlocksIsRefused(): void
    {
        $this->expectException(UnexpectedResultTypeException::class);

        $this->streamed([$this->textBlock(), $this->text('{"verdict":'), $this->textBlock(), $this->text('"approve"}')]);
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<string>
     */
    private function streamed(array $events): array
    {
        $blocks = new AnthropicTextBlocks(new InMemoryRawResult(dataStream: $events));
        $answer = new StreamedAnswer($blocks);
        $stream = new StreamResult($answer->of(new StreamResult($this->deltasOf($blocks))));
        $stream->addListener($answer);
        $stream->addListener($this->recorder());

        $emitted = [];

        foreach ($stream->getContent() as $delta) {
            if ($delta instanceof TextDelta) {
                $emitted[] = $delta->getText();
            }
        }

        return $emitted;
    }

    private function recorder(): AbstractStreamListener
    {
        $seen = &$this->seenAfter;

        return new class($seen) extends AbstractStreamListener {
            /**
             * @param list<string> $seen
             */
            public function __construct(private array &$seen)
            {
            }

            public function onDelta(DeltaEvent $event): void
            {
                $delta = $event->getDelta();

                if ($delta instanceof TextDelta) {
                    $this->seen[] = $delta->getText();
                }
            }
        };
    }

    /**
     * @return Generator<DeltaInterface>
     */
    private function deltasOf(AnthropicTextBlocks $blocks): Generator
    {
        foreach ($blocks->getDataStream() as $event) {
            if ('content_block_delta' === $event['type']) {
                yield new TextDelta($event['delta']['text']);
            }

            if ('tool_use' === ($event['content_block']['type'] ?? null)) {
                yield new ToolCallStart('toolu_01', 'read_file');
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function textBlock(): array
    {
        return ['type' => 'content_block_start', 'content_block' => ['type' => 'text', 'text' => '']];
    }

    /**
     * @return array<string, mixed>
     */
    private function text(string $text): array
    {
        return ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => $text]];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolUse(): array
    {
        return ['type' => 'content_block_start', 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'read_file', 'input' => []]];
    }
}
