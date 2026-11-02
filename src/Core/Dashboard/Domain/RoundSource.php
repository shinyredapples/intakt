<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// Non-empty parts only, because the button must not start a round from a half-recorded start.
final readonly class RoundSource
{
    public function __construct(
        public string $repository,
        public string $base,
        public string $task,
    ) {
    }
}
