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
        return new self('The shadow round of project "'.$project.'" was held: no account of provider "'.$provider.'" is below the shadow ceiling ('.$decision->value.').');
    }
}
