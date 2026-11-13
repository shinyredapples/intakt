<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Domain;

use Intakt\Plugin\Forge\Contract\CommitId;

// Main's boot result holds for one main commit and one set of boot tools, so either changing is a new key.
final readonly class BootKey
{
    public function __construct(
        public string $project,
        public CommitId $main,
        public string $toolFingerprint,
    ) {
    }

    public function equals(self $other): bool
    {
        return $this->project === $other->project
            && $this->main->equals($other->main)
            && $this->toolFingerprint === $other->toolFingerprint;
    }
}
