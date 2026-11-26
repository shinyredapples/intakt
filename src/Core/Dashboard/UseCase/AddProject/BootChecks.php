<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\AddProject;

use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Platform\Contract\Redactor;
use Intakt\Core\Watcher\Contract\FailingMain;
use Intakt\Core\Watcher\Contract\FailingMains;

// Reads the same boot lines as the board's boot banner and the setup wizard's check, because the flow's check shows only what the boot gate recorded.
final readonly class BootChecks
{
    public function __construct(
        private FailingMains $failingMains,
        private Ledger $ledger,
        private Redactor $redactor,
    ) {
    }

    /**
     * @return list<FailingMain>
     */
    public function failingMains(): array
    {
        // Redacted as on the board, because a boot reason can quote a tool's output.
        return array_map(
            fn (FailingMain $main): FailingMain => new FailingMain($main->project, $main->main, $main->toolFingerprint, $this->redactor->redact($main->reason)),
            $this->failingMains->in($this->ledger->readInOrder()),
        );
    }
}
