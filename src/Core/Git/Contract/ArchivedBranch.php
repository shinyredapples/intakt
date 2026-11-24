<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ArchivedBranch
{
    public function __construct(
        public string $branch,
        public string $commit,
        public string $ref,
        public string $mirror,
    ) {
    }
}
