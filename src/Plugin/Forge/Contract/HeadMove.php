<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Intakt\Core\Git\Contract\Paths;

final readonly class HeadMove
{
    public function __construct(
        public HeadMoveKind $kind,
        public Paths $paths,
    ) {
    }
}
