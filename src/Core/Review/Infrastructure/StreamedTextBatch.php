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

    // Published only when a tool call follows, because only then is a turn's text known to be narration: the turn that ends the call is the answer, which reaches the live view only as read (LiveProgress::answered()).
    public function release(): void
    {
        if ('' === $this->turn) {
            return;
        }

        $text = $this->turn;
        $this->turn = '';
        ($this->publish)($text);
    }
}
