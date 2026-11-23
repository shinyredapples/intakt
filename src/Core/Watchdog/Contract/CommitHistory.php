<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Contract;

// The classifier's only view of main, so its rules are tested on forced histories and the git reading stays outside the domain. MainNotRead propagates through the classifier, because a read that failed proves nothing about main.
interface CommitHistory
{
    // Asked for the last seen head, because a force push that rewrote main's history leaves it out.
    /**
     * @throws MainNotRead
     */
    public function contains(string $commit): bool;

    // Oldest first, because the violations are reported in the order the commits reached main. It throws when main no longer holds the commit, because a force push can land after contains() answered.
    /**
     * @return list<string>
     *
     * @throws MainHistoryRewritten
     * @throws MainNotRead
     */
    public function firstParentsSince(string $commit): array;

    /**
     * @throws MainNotRead
     */
    public function signatureOf(string $commit): SignatureStatus;

    // Only what the head holds and its base does not, because a fast-forward puts these commits on main's first-parent line and everything below the base was main's already.
    /**
     * @return list<string>
     *
     * @throws MainNotRead
     */
    public function broughtInBy(RecordedMerge $merge): array;
}
