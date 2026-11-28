<?php

declare(strict_types=1);

namespace Intakt\Core\Git\Contract;

// The head is the signed one, because that is the commit the remote branch holds; the change records are how many records of the branch's pull request the ledger held when the push returned, so the pull request step can tell whether a later run wrote it since.
final readonly class PushedBranch
{
    public function __construct(
        public string $head,
        public int $changeRecords = 0,
    ) {
    }
}
