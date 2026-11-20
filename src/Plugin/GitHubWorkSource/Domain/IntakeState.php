<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

// What a sync reads before its listing: the validator it sends and the generation its write must still find.
final readonly class IntakeState
{
    public function __construct(
        public ?string $validator,
        public int $generation,
    ) {
    }

    public static function none(): self
    {
        return new self(null, 0);
    }
}
