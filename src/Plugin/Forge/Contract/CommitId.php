<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

final readonly class CommitId
{
    public function __construct(public string $hash)
    {
        if (1 !== preg_match('/^([0-9a-f]{40}|[0-9a-f]{64})\z/', $hash)) {
            throw new CommitIdMalformed('A commit id is a full lowercase hexadecimal hash.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->hash === $other->hash;
    }
}
