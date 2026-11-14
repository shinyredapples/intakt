<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Domain;

final readonly class HeldChange
{
    public function __construct(
        public string $commit,
        public string $diff,
    ) {
    }
}
