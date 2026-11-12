<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Generator;
use Intakt\Core\Review\Infrastructure\AnthropicTextBlocks;
use Intakt\Core\Review\Infrastructure\StreamedAnswer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\StreamResult;
use Throwable;

#[CoversClass(StreamedAnswer::class)]
#[CoversClass(AnthropicTextBlocks::class)]
final class StreamedAnswerTest extends TestCase
{
    private TextSeenByLaterListeners $later;

    protected function setUp(): void
    {
        $this->later = new TextSeenByLaterListeners();
    }

    public function testTheTextStillStreamsPieceByPieceAndOnlyOnce(): void
    {
        $emitted = $this->streamed([$this->textBlock(), $this->text('{"verdict":'), $this->text('"approve"}')]);

        self::assertSame(['{"verdict":', '"approve"}'], $emitted);
    }

    public function testTheListenersAfterItSeeTheWholeAnswerOnceWhenTheRoundEnds(): void
    {
        $this->streamed([$this->textBlock(), $this->text('{"verdict":'), $this->text('"approve"}')]);

        self::assertSame(['{"verdict":"approve"}'], $this->later->seen());
    }

    public function testTheListenersAfterItSeeNoTextOfARoundThatAsksForTools(): void
    {
        $emitted = $this->streamed([$this->textBlock(), $this->text('I will read the cart.'), $this->toolUse()]);

        self::assertSame([], $this->later->seen());
        self::assertSame(['I will read the cart.'], $emitted);
    }

    public function testAnAnswerSplitAcrossTwoTextBlocksIsRefused(): void
    {
        $this->expectException(UnexpectedResultTypeException::class);

        $this->streamed([$this->textBlock(), $this->text('{"verdict":'), $this->textBlock(), $this->text('"approve"}')]);
    }

    public function testAServerErrorOnceTheAnswerStartedIsACutAnswerThatKeepsTheErrorAsItsCause(): void
    {
        $outage = new ServerException(503);

        $cut = $this->thrownBy($this->brokenAfter([new TextDelta('{"verdict":')], $outage));

        self::assertInstanceOf(IncompleteStreamException::class, $cut);
        self::assertSame('The provider failed after the answer had started streaming.', $cut->getMessage());
        self::assertSame($outage, $cut->getPrevious());
    }

    public function testAServerErrorBeforeAnyDeltaStaysTheProvidersFailure(): void
    {
        $outage = new ServerException(503);

        self::assertSame($outage, $this->thrownBy($this->brokenAfter([], $outage)));
    }

    public function testAFailureThatARetryCannotOvercomeStaysItselfAlsoMidStream(): void
    {
        $refused = new BadRequestException('Refused.');

        self::assertSame($refused, $this->thrownBy($this->brokenAfter([new TextDelta('{"verdict":')], $refused)));
    }

    /**
     * @param list<DeltaInterface> $deltas
     */
    private function brokenAfter(array $deltas, Throwable $outage): StreamResult
    {
        $broken = static function () use ($deltas, $outage): Generator {
            yield from $deltas;

            throw $outage;
        };

        return new StreamResult(new StreamedAnswer(new AnthropicTextBlocks(new InMemoryRawResult()))->of(new StreamResult($broken())));
    }

    private function thrownBy(StreamResult $stream): ?Throwable
    {
        try {
            iterator_to_array($stream->getContent(), false);
        } catch (Throwable $thrown) {
            return $thrown;
        }

        return null;
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
        $stream->addListener($this->later);

        $emitted = [];

        foreach ($stream->getContent() as $delta) {
            if ($delta instanceof TextDelta) {
                $emitted[] = $delta->getText();
            }
        }

        return $emitted;
    }

    /**
     * @return Generator<DeltaInterface>
     */
    private function deltasOf(AnthropicTextBlocks $blocks): Generator
    {
        foreach ($blocks->getDataStream() as $event) {
            $delta = $event['delta'] ?? null;
            $block = $event['content_block'] ?? null;

            if (is_array($delta) && is_string($delta['text'] ?? null)) {
                yield new TextDelta($delta['text']);
            }

            if (is_array($block) && 'tool_use' === ($block['type'] ?? null)) {
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
