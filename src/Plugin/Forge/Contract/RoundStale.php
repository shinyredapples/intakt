<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class RoundStale extends RuntimeException implements ReportableFailure
{
    public static function on(Change $change): self
    {
        return new self(sprintf('The head of "%s" moved away from %s, so the round\'s review is not posted.', $change->id->value, $change->commits->head->hash));
    }
}
