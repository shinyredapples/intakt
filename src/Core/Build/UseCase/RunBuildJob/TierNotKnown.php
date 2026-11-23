<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\RunBuildJob;

use Intakt\Core\Ai\Contract\Tier;
use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class TierNotKnown extends RuntimeException implements ReportableFailure
{
    public static function named(string $value): self
    {
        return new self(sprintf('The tier "%s" is not one of %s.', $value, implode(', ', array_map(static fn (Tier $tier): string => $tier->value, Tier::cases()))));
    }
}
