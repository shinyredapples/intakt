<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\JobEnd;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ContainerEnd
{
    public function __construct(
        public JobEnd $end,
        public string $standardError,
        public string $mark,
    ) {
    }
}
