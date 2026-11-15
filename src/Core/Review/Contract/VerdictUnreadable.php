<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use RuntimeException;

// Thrown instead of a verdict, because an answer Intakt cannot read is no verdict: reading it as one would let a garbled answer count as a vote, so it fails the reviewer instead.
final class VerdictUnreadable extends RuntimeException
{
    public const string IN_WORDS = 'the reviewer\'s answer could not be read as a verdict';

    private function __construct(string $reason, private readonly ?string $keySafeReason)
    {
        parent::__construct('The reviewer\'s answer could not be read as a verdict: '.$reason);
    }

    // The reason may echo the provider's text, which can carry a key, so it stays in the message and is never recorded.
    public static function because(string $reason): self
    {
        return new self($reason, null);
    }

    // Only for a reason built from Intakt's own words and values, because it is recorded where the owner reads it.
    public static function becauseKeySafe(string $reason): self
    {
        return new self($reason, $reason);
    }

    public function keySafeInWords(): ?string
    {
        return null === $this->keySafeReason ? null : self::IN_WORDS.': '.$this->keySafeReason;
    }
}
