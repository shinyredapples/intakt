<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

// config/gates/src/GitCall.php is the protected copy the gates use, because a gate may depend on nothing outside a protected path; it keeps plain arrays, since the gates have no module contracts.
final readonly class GitCall
{
    public function __construct(
        public GitCommand $command,
        public string $directory,
        public GitEnvironment $environment,
    ) {
    }
}
