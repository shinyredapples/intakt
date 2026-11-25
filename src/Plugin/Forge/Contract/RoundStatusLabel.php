<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Intakt\Core\Review\Contract\ReviewStatus;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Derived from the round's status, so a new status gets its label without a second spelling.
#[Exclude]
final readonly class RoundStatusLabel
{
    public const string FAMILY_PREFIX = 'status:';

    public static function of(ReviewStatus $status): string
    {
        return self::FAMILY_PREFIX.$status->value;
    }

    public static function isStatus(string $label): bool
    {
        return str_starts_with($label, self::FAMILY_PREFIX);
    }
}
