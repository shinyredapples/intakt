<?php

declare(strict_types=1);

namespace Intakt\Plugin\WorkSource\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Carries who wrote the line, because only a line by an account with write access or by Intakt's own App may count as a wait.
#[Exclude]
final readonly class Wait
{
    public function __construct(
        public int $target,
        public bool $byTrustedAccount,
    ) {
    }
}
