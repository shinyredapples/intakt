<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ServeReviewTools;

use Intakt\Core\Platform\Contract\ReportableFailure;
use LogicException;

// Reportable, because the operator who asks for a credential fixes it by setting the secret.
final class ReviewCredentialNeedsSecret extends LogicException implements ReportableFailure
{
    public static function whileTheSecretIsEmpty(): self
    {
        return new self('APP_SECRET is empty, so no review credential can be issued.');
    }
}
