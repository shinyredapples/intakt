<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Plugin\Forge\Contract\HeadMove;

final readonly class HeadIdentity
{
    public function __construct(
        public VerdictIdentity $identity,
        public HeadMove $move,
    ) {
    }
}
