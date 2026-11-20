<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

// The validator a listing was requested with and the one stored after it, so a listing is written only over the state it was read against.
final readonly class ValidatorChange
{
    public function __construct(
        public ?string $from,
        public ?string $to,
    ) {
    }
}
