<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

final readonly class ReplayMarkOfProject
{
    public function __construct(public string $project)
    {
    }
}
