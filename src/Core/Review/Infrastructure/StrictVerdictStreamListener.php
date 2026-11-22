<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Closure;
use Intakt\Core\Review\Contract\StreamCutBy;
use Intakt\Core\Review\Infrastructure\ReviewerAnswer\Verdict as ReviewerAnswer;
use Override;
use Symfony\AI\Platform\Result\Stream\AbstractStreamListener;
use Symfony\AI\Platform\Result\Stream\CompleteEvent;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\DeltaEvent;
use Symfony\AI\Platform\StructuredOutput\Streaming\PartialJsonParser;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Needed because Symfony AI's own listener, which assembles the object the agent returns from a stream, maps leniently; excluded from the container because one is built per stream, so its text never outlives the answer.
#[Exclude]
final class StrictVerdictStreamListener extends AbstractStreamListener
{
    private string $answer = '';

    private bool $askedForTools = false;

    /**
     * @param Closure(string): ReviewerAnswer $verdictFrom
     */
    public function __construct(
        private readonly Closure $verdictFrom,
        private readonly StreamTally $tally,
    ) {
    }

    #[Override]
    public function onDelta(DeltaEvent $event): void
    {
        $delta = $event->getDelta();

        if ($delta instanceof ToolCallComplete) {
            $this->askedForTools = true;
        }

        if ($delta instanceof TextDelta) {
            $this->answer .= $delta->getText();
        }
    }

    #[Override]
    public function onComplete(CompleteEvent $event): void
    {
        // Skipped because a round that asks for tools is a step towards the verdict, as in the unstreamed conversion.
        if ($this->askedForTools) {
            return;
        }

        // Raised as a cut-off, because a stream the provider did not report as cut off can still end before or inside its document, and no or half a verdict is no verdict.
        if ($this->unfinished()) {
            throw $this->tally->cut(StreamCutBy::UnfinishedAnswer, 'The reviewer stream ended before its answer was complete.');
        }

        ($this->verdictFrom)($this->answer);
    }

    private function unfinished(): bool
    {
        if ('' === trim($this->answer)) {
            return true;
        }

        return $this->stillOpen() && null !== PartialJsonParser::parse($this->answer);
    }

    // Scanned here because a closed document with a syntax error is a refused verdict, not a cut-off, and Symfony AI's parser repairs both alike.
    private function stillOpen(): bool
    {
        $depth = 0;
        $inString = false;
        $escaped = false;

        foreach (str_split($this->answer) as $char) {
            if ($inString) {
                $inString = $escaped || '"' !== $char;
                $escaped = !$escaped && '\\' === $char;

                continue;
            }

            $inString = '"' === $char;
            $depth += match ($char) {
                '{', '[' => 1,
                '}', ']' => -1,
                default => 0,
            };
        }

        return $inString || $depth > 0;
    }
}
