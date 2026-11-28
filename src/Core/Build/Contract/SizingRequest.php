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
        // Set only by plain code, because on a small pool it decides a split the model's size did not name and then asks once for the parts.
        public bool $partsAsked = false,
    ) {
    }

    public function askingForParts(): self
    {
        return clone($this, ['partsAsked' => true]);
    }
}
