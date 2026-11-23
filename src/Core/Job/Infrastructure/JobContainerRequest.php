<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The create request together with the mark its start check logs with, because only the lines carrying that mark are the check's own.
#[Exclude]
final readonly class JobContainerRequest
{
    /**
     * @param array<string, mixed> $definition
     */
    public function __construct(
        public array $definition,
        public string $mark,
    ) {
    }
}
