<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\Infrastructure;

use Generator;
use Intakt\Core\Review\Contract\StreamChunks;
use Intakt\Core\Review\Contract\StreamCutBy;
use Intakt\Core\Review\Contract\StreamEndedEarly;
use Intakt\Core\Review\Infrastructure\AnthropicTextBlocks;
use Intakt\Core\Review\Infrastructure\StreamedAnswer;
use Intakt\Core\Review\Infrastructure\StreamTally;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\MetadataDelta;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\TokenUsage\TokenUsage;
use Throwable;

#[CoversClass(StreamedAnswer::class)]
#[CoversClass(AnthropicTextBlocks::class)]
#[CoversClass(StreamTally::class)]
#[CoversClass(StreamEndedEarly::class)]
#[UsesClass(StreamChunks::class)]
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

    public function testAServerErrorAfterOnlyTheMessageStartUsageStaysTheProvidersFailure(): void
    {
        $outage = new ServerException(errorMessage: 'Overloaded');

        self::assertSame($outage, $this->thrownBy($this->brokenAfter([new TokenUsage(promptTokens: 120)], $outage)));
    }

    public function testAServerErrorAfterOnlyMetadataStaysTheProvidersFailure(): void
    {
        $outage = new ServerException(503);

        self::assertSame($outage, $this->thrownBy($this->brokenAfter([new MetadataDelta('finish_reason', null)], $outage)));
    }

    public function testAServerErrorOnceAThinkingDeltaStreamedIsACutAnswer(): void
    {
        $cut = $this->thrownBy($this->brokenAfter([new TokenUsage(promptTokens: 120), new ThinkingDelta('Reading the diff.')], new ServerException(503)));

        self::assertInstanceOf(IncompleteStreamException::class, $cut);
    }

    public function testAServerErrorOnceTheAnswerStartedKeepsTheChunksThatCameBeforeIt(): void
    {
        $cut = $this->thrownBy($this->brokenAfter([new TokenUsage(promptTokens: 120), new TextDelta('{"verdict":')], new ServerException(503)));

        self::assertInstanceOf(StreamEndedEarly::class, $cut);
        self::assertSame([StreamCutBy::ProviderFailureMidStream, 2], [$cut->by, $cut->chunks]);
        self::assertSame(['TokenUsage', 'TextDelta "{\"verdict\":"'], [...$cut->lastChunks]);
    }

    public function testAStreamThePlatformCutIsRaisedAgainWithItsMessageCauseAndTheChunksBeforeIt(): void
    {
        $cause = new ServerException(502);
        $platformCut = new IncompleteStreamException('Completions stream ended before a finish reason was received.', 0, $cause);

        $cut = $this->thrownBy($this->brokenAfter([new TextDelta('{"verdict":'), new MetadataDelta('finish_reason', null)], $platformCut));

        self::assertInstanceOf(StreamEndedEarly::class, $cut);
        self::assertSame(['Completions stream ended before a finish reason was received.', $cause], [$cut->getMessage(), $cut->getPrevious()]);
        self::assertSame([StreamCutBy::Platform, 2], [$cut->by, $cut->chunks]);
        self::assertSame(['TextDelta "{\"verdict\":"', 'MetadataDelta finish_reason=null'], [...$cut->lastChunks]);
    }

    public function testAStreamThePlatformCutBeforeAnyChunkKeepsNone(): void
    {
        $cut = $this->thrownBy($this->brokenAfter([], new IncompleteStreamException('The stream ended before its last event.')));

        self::assertInstanceOf(StreamEndedEarly::class, $cut);
        self::assertSame([0, []], [$cut->chunks, [...$cut->lastChunks]]);
        self::assertStringContainsString('last chunks: none', $cut->described());
    }

    public function testEveryChunkIsCountedButOnlyTheLastFiveAreKept(): void
    {
        $deltas = array_map(static fn (int $n): TextDelta => new TextDelta((string) $n), range(1, 7));

        $cut = $this->thrownBy($this->brokenAfter($deltas, new IncompleteStreamException('Cut.')));

        self::assertInstanceOf(StreamEndedEarly::class, $cut);
        self::assertSame(7, $cut->chunks);
        self::assertSame(['TextDelta "3"', 'TextDelta "4"', 'TextDelta "5"', 'TextDelta "6"', 'TextDelta "7"'], [...$cut->lastChunks]);
    }

    public function testAKeptChunkShowsOnlyTheFirstEightyCharactersOfItsText(): void
    {
        $cut = $this->thrownBy($this->brokenAfter([new ThinkingDelta(str_repeat('é', 79).'ab')], new IncompleteStreamException('Cut.')));

        self::assertInstanceOf(StreamEndedEarly::class, $cut);
        self::assertSame(['ThinkingDelta "'.str_repeat('é', 79).'a"'], [...$cut->lastChunks]);
    }

    public function testAKeptMetadataChunkShowsAScalarValueAndOnlyTheTypeOfAnyOther(): void
    {
        $cut = $this->thrownBy($this->brokenAfter([new MetadataDelta('finish_reason', 'stop'), new MetadataDelta('logprobs', ['a' => 1])], new IncompleteStreamException('Cut.')));

        self::assertInstanceOf(StreamEndedEarly::class, $cut);
        self::assertSame(['MetadataDelta finish_reason="stop"', 'MetadataDelta logprobs=array'], [...$cut->lastChunks]);
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

        return new StreamResult(new StreamedAnswer(new AnthropicTextBlocks(new InMemoryRawResult()), new StreamTally())->of(new StreamResult($broken())));
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
        $answer = new StreamedAnswer($blocks, new StreamTally());
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
