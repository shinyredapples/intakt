<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

// The classifier's only view of main, so its rules are tested on forced histories and the git reading stays outside the domain.
interface CommitHistory
{
    // Asked for the last seen head, because a force push that rewrote main's history leaves it out.
    public function contains(string $commit): bool;

    // Oldest first, because the violations are reported in the order the commits reached main. It throws when main no longer holds the commit, because a force push can land after contains() answered.
    /**
     * @return list<string>
     *
     * @throws MainHistoryRewritten
     */
    public function firstParentsSince(string $commit): array;

    public function signatureOf(string $commit): SignatureStatus;
}
