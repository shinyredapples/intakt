<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Intakt\Core\Review\Contract\Severity;

// The review page's word for a severity, so the owner reads how soon to fix instead of a P number; the P number stays in the finding's tooltip.
enum FindingUrgency: string
{
    case Urgent = 'urgent';
    case ShouldFix = 'should-fix';
    case NiceToHave = 'nice-to-have';

    // Null for a finding whose severity was not recorded, because a guessed urgency would rank it.
    public static function of(?string $severity): ?self
    {
        return match (Severity::tryFrom($severity ?? '')) {
            Severity::P1 => self::Urgent,
            Severity::P2 => self::ShouldFix,
            Severity::P3 => self::NiceToHave,
            null => null,
        };
    }

    public function words(): string
    {
        return match ($this) {
            self::Urgent => 'Urgent',
            self::ShouldFix => 'Should fix',
            self::NiceToHave => 'Nice to have',
        };
    }
}
