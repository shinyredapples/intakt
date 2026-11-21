<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

// Lowercased, because forge addresses do not tell owners or repositories apart by letter case, so one pull request named in two spellings must be one change with one round sequence.
final readonly class ChangeAddress
{
    public static function canonical(string $address): string
    {
        return strtolower($address);
    }
}
