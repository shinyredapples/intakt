<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunCheckJob;

// The blob too, so the check job can prove its merged tree holds the very bytes the snapshot's key was made of.
final readonly class CheckedLockFile
{
    public function __construct(
        public string $blob,
        public string $bytes,
    ) {
    }
}
