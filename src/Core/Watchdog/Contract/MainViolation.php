<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

final readonly class MainViolation
{
    public string $commit;

    public function __construct(
        string $commit,
        public Violation $violation,
    ) {
        $this->commit = CommitName::full($commit, 'violating commit');
    }
}
