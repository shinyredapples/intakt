<?php

declare(strict_types=1);

namespace Intakt\Core\Build\UseCase\BuildTask;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class TaskRef
{
    public function __construct(
        public string $project,
        public int $number,
    ) {
    }
}
