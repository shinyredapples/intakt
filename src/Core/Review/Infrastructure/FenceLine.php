<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure;

final readonly class FenceLine
{
    private function __construct(
        private string $prefix,
        private string $run,
        private string $info,
    ) {
    }

    // Up to three spaces count as no prefix, because Markdown still reads such a line as a fence; a tab, deeper indentation, a quote or a list marker is kept, since it puts the line in another container.
    public static function of(string $prefix, string $run, string $info): self
    {
        return new self(1 === preg_match('/^ {0,3}$/', $prefix) ? '' : $prefix, $run, $info);
    }

    // Inside an open block a quoted, listed or deeper indented fence line is content, and a bare fence line after a quoted opening starts a new block instead of closing the quoted one, so only a bare line with the opening's prefix closes it.
    public function closes(self $open): bool
    {
        return '' === $this->info
            && $this->prefix === $open->prefix
            && $this->run[0] === $open->run[0]
            && strlen($this->run) >= strlen($open->run);
    }
}
