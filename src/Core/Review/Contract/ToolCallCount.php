<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class ToolCallCount
{
    public function __construct(
        public string $tool,
        public int $count,
    ) {
    }

    // Counted by the review server under the tool's name, which a reader of the summary should not have to know.
    public function kindInWords(): string
    {
        return ReviewTool::tryFrom($this->tool)?->kindInWords() ?? 'Other tool calls';
    }
}
