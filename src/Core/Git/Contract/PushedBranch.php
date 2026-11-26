<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

// The head is the signed one, because that is the commit the remote branch holds.
final readonly class PushedBranch
{
    public function __construct(
        public string $head,
    ) {
    }
}
