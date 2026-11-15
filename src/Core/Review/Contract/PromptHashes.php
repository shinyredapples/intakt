<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Hashes of the instruction text, not of a role's prompt or the fenced task, because those come from the project's panel and the change.
final readonly class PromptHashes
{
    public function __construct(
        public string $reviewer,
        public string $judge,
    ) {
    }
}
