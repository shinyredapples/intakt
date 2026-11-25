<?php

declare(strict_types=1);

namespace Intakt\Plugin\WorkSource\Contract;

// Carries who wrote the line, because only a line by an account with write access or by Intakt's own App may count as a wait.
final readonly class Wait
{
    public function __construct(
        public int $target,
        public bool $byTrustedAccount,
    ) {
    }
}
