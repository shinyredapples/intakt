<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\Domain;

enum SignatureStatus
{
    case ByOwnerKey;
    case NoSignature;
    case NotByOwnerKey;

    public static function fromGit(string $letter): self
    {
        // Because anything but G and N (another key, a bad, expired or revoked signature, one git could not check) must fail closed as not the owner's.
        return match ($letter) {
            'G' => self::ByOwnerKey,
            'N' => self::NoSignature,
            default => self::NotByOwnerKey,
        };
    }
}
