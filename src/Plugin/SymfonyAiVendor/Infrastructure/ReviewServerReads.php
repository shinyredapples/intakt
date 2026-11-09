<?php

declare(strict_types=1);

namespace Intakt\Plugin\SymfonyAiVendor\Infrastructure;

use Intakt\Core\Review\Contract\Fence;
use Intakt\Core\Review\Contract\UntrustedSource;
use Intakt\Core\Review\Contract\Verdict;

// A subscription CLI reads the review server's answers without Intakt's toolbox, so its approval keeps the toolbox's rules here: the server fences every answer and its refusals for that CLI, so only an answer that is exactly one fence was read whole; a fence followed by more is cut (the server's note after the closing marker), and anything else is a read that failed on the way.
final readonly class ReviewServerReads
{
    public function __construct(
        private Fence $fence = new Fence(),
    ) {
    }

    public function approval(Verdict $approval, string ...$answers): Verdict
    {
        $unread = array_filter($answers, fn (string $answer): bool => !$this->fence->wraps(UntrustedSource::ToolResult, $answer));

        return match (true) {
            [] === $unread => $approval,
            array_all($unread, $this->isCut(...)) => Verdict::cutApproval(),
            default => Verdict::unreadApproval(),
        };
    }

    // The last closing marker ends the fence, because the note after it holds none.
    private function isCut(string $answer): bool
    {
        return 1 === preg_match('/\A(.*\n<<<END fence-[0-9a-f]{16}>>>).+\z/s', $answer, $parts) && $this->fence->wraps(UntrustedSource::ToolResult, $parts[1]);
    }
}
