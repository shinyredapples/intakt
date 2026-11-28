<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class SizingRequest
{
    /**
     * @param list<string> $labels
     */
    public function __construct(
        public string $project,
        public int $task,
        // Untrusted, like the body, because both come from the issue author and so reach the prompt only fenced.
        public string $title,
        public string $body,
        // Never sent to the model, because only a split reads them: each part gets its original's labels.
        public array $labels = [],
    ) {
    }
}
