<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubWorkSource\Domain;

use Intakt\Core\Build\Contract\FinishedSplit;

// What one close step did, because a close the forge refused stays open until a later listing and its caller must say so.
final readonly class ClosedUmbrellas
{
    /**
     * @param list<FinishedSplit>  $closed
     * @param array<int, string>   $stayedOpen the reason of each umbrella the forge did not close, by its number
     */
    public function __construct(
        public array $closed,
        public array $stayedOpen,
    ) {
    }
}
