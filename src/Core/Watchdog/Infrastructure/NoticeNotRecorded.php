<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Infrastructure;

use RuntimeException;

final class NoticeNotRecorded extends RuntimeException
{
    // Because the ledger took these before it refused this one, and the owner has not seen them yet.
    /** @var list<string> */
    public private(set) array $givenBefore = [];

    public static function noticing(string $commit, string $failure): self
    {
        return new self(sprintf('The notice for %s was not recorded, because the ledger refused it: %s.', $commit, $failure));
    }

    /**
     * @param list<string> $given
     */
    public function afterGiving(array $given): self
    {
        $this->givenBefore = $given;

        return $this;
    }
}
