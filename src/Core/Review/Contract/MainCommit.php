<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Asks which commit the base branch is at, because the watcher judges a head against that commit's boot result and the forge alone knows it.
final readonly class MainCommit
{
    public function __construct(public ChangeSource $source)
    {
    }
}
