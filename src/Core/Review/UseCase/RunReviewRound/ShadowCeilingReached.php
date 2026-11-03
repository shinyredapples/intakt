<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\RunReviewRound;

use Intakt\Core\Budget\Contract\StartDecision;
use Intakt\Core\Platform\Contract\ReportableFailure;
use RuntimeException;

final class ShadowCeilingReached extends RuntimeException implements ReportableFailure
{
    public static function by(string $project, string $provider, StartDecision $decision): self
    {
        return new self('The shadow round of project "'.$project.'" was held: an account of provider "'.$provider.'" '.self::isNot($decision).' ('.$decision->value.').');
    }

    private static function isNot(StartDecision $decision): string
    {
        $unavailable = 'is not available for a start, because ';

        return match ($decision) {
            StartDecision::AtCeiling => 'is not below the shadow ceiling',
            StartDecision::Start => 'was held without a reason',
            StartDecision::NoTrustedReading => $unavailable.'it has no trusted usage reading',
            StartDecision::ReadingNotFreshEnough => $unavailable.'its usage reading is not fresh enough for a start next to a running call',
            StartDecision::NoMarginForParallelStart => $unavailable.'it has no room left for a start next to a running call',
            StartDecision::CoolingDown => $unavailable.'it is cooling down',
            StartDecision::BudgetBackedOff => $unavailable.'Intakt is backed off for its budget',
            StartDecision::Paused => $unavailable.'Intakt is paused',
            StartDecision::FailureBackedOff => $unavailable.'Intakt is backed off after failures',
            StartDecision::Draining => $unavailable.'Intakt is draining',
        };
    }
}
