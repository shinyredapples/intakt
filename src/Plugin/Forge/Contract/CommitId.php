<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use InvalidArgumentException;

final readonly class CommitId
{
    public function __construct(public string $hash)
    {
        if (1 !== preg_match('/^([0-9a-f]{40}|[0-9a-f]{64})$/', $hash)) {
            throw new InvalidArgumentException('A commit id is a full lowercase hexadecimal hash.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->hash === $other->hash;
    }
}
