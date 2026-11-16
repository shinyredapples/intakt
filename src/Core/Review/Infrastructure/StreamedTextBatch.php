<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Closure;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// One per reviewer call and never shared, because the readonly services must keep no text of their own and two reviewers at once must not mix; excluded from the container for the same reason, so its text never outlives the call.
#[Exclude]
final class StreamedTextBatch
{
    private string $turn = '';

    /**
     * @param Closure(string): void $publish
     */
    public function __construct(
        private readonly Closure $publish,
    ) {
    }

    public function add(string $text): void
    {
        $this->turn .= $text;
    }

    // Published only when a tool call follows, because only then may a turn's text be narration: the turn that ends the call is the answer, which reaches the live view only as read (LiveProgress::answered()).
    public function release(): void
    {
        $text = $this->turn;
        $this->turn = '';

        if ('' === $text || $this->holdsAJsonObject($text)) {
            return;
        }

        ($this->publish)($text);
    }

    // Dropped even though a tool call follows, because a model may write its answer and then call a tool anyway, and the answer's JSON reaches the live view only as read; prose around it and fences do not hide it.
    private function holdsAJsonObject(string $text): bool
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        return false !== $start && false !== $end && $start < $end && json_validate(substr($text, $start, $end - $start + 1));
    }
}
