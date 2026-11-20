<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

// The classifier's only view of main, so its rules are tested on forced histories and the git reading stays outside the domain.
interface CommitHistory
{
    // False after a force push that dropped the commit from main's history.
    public function contains(string $commit): bool;

    /**
     * @return list<string> main's first-parent commits after the given one, oldest first
     */
    public function firstParentsSince(string $commit): array;

    public function signatureOf(string $commit): SignatureStatus;
}
