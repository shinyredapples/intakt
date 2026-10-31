<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

use LogicException;

final class ReviewerNotStarted extends LogicException
{
    public static function for(string $reviewerId): self
    {
        return new self('The reviewer '.$reviewerId.' has no conversation, because it was never started.');
    }
}
