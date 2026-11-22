<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

// Read before a listing: the validator it is asked with, and the generation a write compares, because a listing of several pages carries no validator to compare.
final readonly class SyncState
{
    public function __construct(
        public ?string $validator,
        public int $generation,
    ) {
    }

    public static function neverSynced(): self
    {
        return new self(null, 0);
    }
}
