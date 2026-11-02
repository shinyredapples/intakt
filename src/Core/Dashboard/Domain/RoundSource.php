<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// What a round's start recorded about where its change was read from; every part is non-empty, or the round has no source.
final readonly class RoundSource
{
    public function __construct(
        public string $repository,
        public string $base,
        public string $task,
    ) {
    }
}
