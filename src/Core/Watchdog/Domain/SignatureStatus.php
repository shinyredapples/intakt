<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Domain;

// What git's %G? prints for a commit, checked against the owner's keys only.
enum SignatureStatus
{
    case ByOwnerKey;
    case NoSignature;
    case NotByOwnerKey;

    public static function fromGit(string $letter): self
    {
        // Every letter but G and N (a valid signature of another key, a bad, expired or revoked one, one git could not check) fails closed as not the owner's.
        return match ($letter) {
            'G' => self::ByOwnerKey,
            'N' => self::NoSignature,
            default => self::NotByOwnerKey,
        };
    }
}
