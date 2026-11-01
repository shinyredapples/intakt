<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadRound;

final readonly class RoundIdentity
{
    public function __construct(
        public string $id,
        public string $change,
        public int $number,
    ) {
    }
}
