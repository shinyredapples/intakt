<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use RuntimeException;

// Thrown instead of a verdict, because an answer Intakt cannot read is no verdict: the vendor asks once more, and a second one fails the reviewer.
final class VerdictUnreadable extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self('The reviewer\'s answer could not be read as a verdict: '.$reason);
    }
}
