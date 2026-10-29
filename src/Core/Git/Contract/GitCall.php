<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

// config/gates/src/GitCall.php is the protected copy the gates use, because a gate may depend on nothing outside a protected path.
final readonly class GitCall
{
    /**
     * @param list<string>                $command
     * @param array<string, string|false> $environment
     */
    public function __construct(
        public array $command,
        public string $directory,
        public array $environment,
    ) {
    }
}
