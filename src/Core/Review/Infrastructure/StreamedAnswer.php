<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Generator;
use Intakt\Core\Review\Contract\StreamCutBy;
use Override;
use Symfony\AI\Platform\Exception\IncompleteStreamException;
use Symfony\AI\Platform\Exception\RetryableExceptionInterface;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\Stream\AbstractStreamListener;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\MetadataDelta;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Needed because Symfony AI's structured output reads every text delta of a stream as the answer, also the narration before a tool call: the listeners after this one see the answer once, when the round ends without asking for tools, while the text still streams as it is written; excluded from the container because one is built per stream.
#[Exclude]
final class StreamedAnswer extends AbstractStreamListener
{
    private const string BROKEN_MID_STREAM = 'The provider failed after the answer had started streaming.';

    private ?TextDelta $answer = null;

    public function __construct(
        private readonly AnthropicTextBlocks $blocks,
        private readonly StreamTally $tally,
    ) {
    }

    /**
     * @return Generator<DeltaInterface>
     */
    public function of(StreamResult $stream): Generator
    {
        $text = '';
        $firstBlock = null;
        $asksForTools = false;

        foreach ($this->cutWhereItBroke($stream) as $delta) {
            $asksForTools = $asksForTools || $delta instanceof ToolCallStart || $delta instanceof ToolCallComplete;

            if ($delta instanceof TextDelta) {
                $firstBlock ??= $this->blocks->opened();
                $text .= $delta->getText();
            }

            yield $delta;
        }

        if ($asksForTools || null === $firstBlock) {
            return;
        }

        // Refused like the unstreamed answer in two text parts, because which one is the verdict would be a guess.
        if ($this->blocks->opened() > $firstBlock) {
            throw new UnexpectedResultTypeException(TextResult::class, MultiPartResult::class);
        }

        yield $this->answer = new TextDelta($text);
    }

    #[Override]
    public function onDelta(DeltaEvent $event): void
    {
        $delta = $event->getDelta();

        // Skipped, because its text already streamed piece by piece.
        if ($delta === $this->answer) {
            $event->skipDelta();

            return;
        }

        if ($delta instanceof TextDelta) {
            // Wrapped in a generator, because the listeners after this one then see no text delta and take only the answer, while the stream still yields the text as written.
            $event->setDelta($this->passedOn($delta));
        }
    }

    // A transient failure once the answer has started streaming is a cut answer, because the provider cannot resume it and asking again would start the review over; before the first answer delta it stays the provider's failure, so another account or a substitute can take the call. Usage and metadata do not start the answer, because a provider reports them on opening the message, often right before an overload error.
    /**
     * @return Generator<DeltaInterface>
     */
    private function cutWhereItBroke(StreamResult $stream): Generator
    {
        $started = false;

        try {
            foreach ($stream->getContent() as $delta) {
                $this->tally->counted($delta);
                $started = $started || !$delta instanceof TokenUsageInterface && !$delta instanceof MetadataDelta;

                yield $delta;
            }
        } catch (RetryableExceptionInterface $broken) {
            throw $started ? $this->tally->cut(StreamCutBy::ProviderFailureMidStream, self::BROKEN_MID_STREAM, $broken) : $broken;
        } catch (IncompleteStreamException $cut) {
            // Raised again with the platform's message and cause, so the retry rules and the account's cooldown read it as before.
            throw $this->tally->cut(StreamCutBy::Platform, $cut->getMessage(), $cut->getPrevious());
        }
    }

    /**
     * @return Generator<TextDelta>
     */
    private function passedOn(TextDelta $delta): Generator
    {
        yield $delta;
    }
}
