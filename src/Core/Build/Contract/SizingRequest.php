<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class SizingRequest
{
    public function __construct(
        public string $project,
        public int $task,
        // Untrusted, like the body: both reach the prompt only fenced.
        public string $title,
        public string $body,
    ) {
    }
}
