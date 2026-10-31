<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

final readonly class HeadMove
{
    /**
     * @param list<string> $paths
     */
    public function __construct(
        public HeadMoveKind $kind,
        public array $paths,
    ) {
    }
}
