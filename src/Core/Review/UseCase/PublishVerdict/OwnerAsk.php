<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\PublishVerdict;

use Intakt\Core\Git\Contract\ProtectedChanges;
use Intakt\Plugin\Forge\Contract\PublishedVerdict;

final readonly class OwnerAsk
{
    public function __construct(
        public ProtectedChanges $changes,
        public PublishedVerdict $verdict,
    ) {
    }
}
