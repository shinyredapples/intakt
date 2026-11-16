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

        if ('' === $text || $this->opensAJsonObject($text)) {
            return;
        }

        ($this->publish)($text);
    }

    // Dropped even though a tool call follows, because a model may write its answer and then call a tool anyway, and the answer's JSON reaches the live view only as read. Matched on an object opening with a key rather than on valid JSON, so prose or braces around it, a second object, a stray comma or a cut-off answer do not hide it.
    private function opensAJsonObject(string $text): bool
    {
        return 1 === preg_match('/\{\s*"/', $text);
    }
}
