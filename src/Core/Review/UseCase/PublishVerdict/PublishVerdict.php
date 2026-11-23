<?php

declare(strict_types=1);

namespace Intakt\Core\Review\UseCase\PublishVerdict;

use Intakt\Core\Platform\Contract\CommandMessage;

final readonly class PublishVerdict implements CommandMessage
{
    public function __construct(
        public string $roundId,
    ) {
    }
}
