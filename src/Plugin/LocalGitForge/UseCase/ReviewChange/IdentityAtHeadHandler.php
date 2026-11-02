<?php

declare(strict_types=1);

namespace Intakt\Plugin\LocalGitForge\UseCase\ReviewChange;

use Intakt\Core\Platform\Contract\AsQueryHandler;
use Intakt\Core\Review\Contract\HeadIdentity;
use Intakt\Core\Review\Contract\IdentityAtHead;

#[AsQueryHandler]
final readonly class IdentityAtHeadHandler
{
    public function __construct(private LocalChangeReview $review)
    {
    }

    public function __invoke(IdentityAtHead $query): HeadIdentity
    {
        return $this->review->identityAt(ReviewChangeInput::startedBy($query->round->start), $query->round->head, $query->reviewed);
    }
}
