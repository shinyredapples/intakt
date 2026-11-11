<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\PublishVerdict;

final readonly class PublishVerdict
{
    public function __construct(
        public string $roundId,
    ) {
    }
}
