<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Generator;
use Override;
use Symfony\AI\Platform\Exception\UnexpectedResultTypeException;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\Stream\AbstractStreamListener;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Needed because Symfony AI's structured output reads every text delta of a stream as the answer, also the narration before a tool call: the listeners after this one see the answer once, when the round ends without asking for tools, while the text still streams as it is written; excluded from the container because one is built per stream.
#[Exclude]
final class StreamedAnswer extends AbstractStreamListener
{
    private ?TextDelta $answer = null;

    public function __construct(
        private readonly AnthropicTextBlocks $blocks,
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

        foreach ($stream->getContent() as $delta) {
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
            $event->setDelta($this->passedOn($delta));
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
