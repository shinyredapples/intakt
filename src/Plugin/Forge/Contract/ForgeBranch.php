<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class ForgeBranch
{
    public function __construct(
        public string $name,
        public CommitId $head,
        public bool $isDefault,
        public bool $isProtected,
    ) {
    }
}
