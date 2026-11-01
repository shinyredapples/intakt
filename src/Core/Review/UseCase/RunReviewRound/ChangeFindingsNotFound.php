<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use RuntimeException;

final class ChangeFindingsNotFound extends RuntimeException
{
    public static function for(string $project, string $change): self
    {
        return new self(sprintf('No finding ledger is stored for change %s of project %s', $change, $project));
    }
}
