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

    // In words, because the review server counts under the tool's name and a reader of the summary should not have to know it.
    public function kindInWords(): string
    {
        return ReviewTool::tryFrom($this->tool)?->kindInWords() ?? 'Other tool calls';
    }
}
