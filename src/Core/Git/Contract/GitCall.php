<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

// config/gates/src/GitCall.php is the protected copy the gates use, as for HardenedGit.
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
