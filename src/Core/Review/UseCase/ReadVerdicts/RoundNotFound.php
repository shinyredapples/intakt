<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ReadVerdicts;

use Intakt\Core\Review\Contract\UnknownRound;
use RuntimeException;

final class RoundNotFound extends RuntimeException implements UnknownRound
{
    public static function for(string $roundId): self
    {
        return new self('No review round is stored under the id '.$roundId);
    }
}
