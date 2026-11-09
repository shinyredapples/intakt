<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class StartCheckItem
{
    public function __construct(
        public string $name,
        public bool $passed,
        public string $reason,
    ) {
    }
}
