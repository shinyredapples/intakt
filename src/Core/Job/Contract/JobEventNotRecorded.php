<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

use RuntimeException;

final class JobEventNotRecorded extends RuntimeException
{
    public static function for(string $kind, string $reason): self
    {
        return new self(sprintf('The ledger did not record the job event "%s": %s', $kind, $reason));
    }
}
