<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Domain;

use Intakt\Plugin\Forge\Contract\OpenChange;

final readonly class HeadFailure
{
    public function __construct(
        public string $project,
        public OpenChange $head,
        public string $cause,
    ) {
    }
}
