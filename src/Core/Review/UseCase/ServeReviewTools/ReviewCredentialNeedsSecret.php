<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\ServeReviewTools;

use LogicException;

final class ReviewCredentialNeedsSecret extends LogicException
{
    public static function whileTheSecretIsEmpty(): self
    {
        return new self('APP_SECRET is empty, so no review credential can be issued.');
    }
}
