<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use RuntimeException;

// Thrown instead of a verdict, because an answer Intakt cannot read is no verdict: reading it as one would let a garbled answer count as a vote, so it fails the reviewer instead.
final class VerdictUnreadable extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self('The reviewer\'s answer could not be read as a verdict: '.$reason);
    }
}
