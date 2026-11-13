<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\ChooseBuildModel;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class ModelChoiceNotRecorded extends RuntimeException implements ReportableFailure
{
    public static function for(BuildAttempt $attempt, string $reason): self
    {
        return new self(sprintf('The ledger did not record the model choice of attempt %d of task "%s": %s', $attempt->number, $attempt->task, $reason));
    }
}
