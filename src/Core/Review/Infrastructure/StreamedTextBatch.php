<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

use Closure;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// One per reviewer call and never shared, because the readonly services must keep no text of their own and two reviewers at once must not mix; excluded from the container for the same reason, so its text never outlives the call.
#[Exclude]
final class StreamedTextBatch
{
    private string $waiting = '';

    /**
     * @param Closure(string): void $publish
     */
    public function __construct(
        private readonly Closure $publish,
    ) {
    }

    public function add(string $text): void
    {
        $this->waiting .= $text;
    }

    // Only on a tool call, because only then is the turn known to be narration: the turn that ends without one is the answer, prose or JSON, readable or not, and it reaches the page as readable lines (LiveProgress::answered()), never as its raw text.
    public function nextTurn(): void
    {
        $text = $this->waiting;
        $this->waiting = '';

        if ('' !== trim($text)) {
            ($this->publish)($text);
        }
    }
}
