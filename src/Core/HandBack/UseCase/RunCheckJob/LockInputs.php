<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunCheckJob;

use Intakt\Core\HandBack\Domain\DependencySnapshotKey;

// The bytes the key is made of, kept together, so the install job installs from exactly what the key names.
final readonly class LockInputs
{
    /**
     * @param non-empty-array<string, string> $files
     */
    public function __construct(
        public array $files,
        public string $platform,
    ) {
    }

    public function key(): DependencySnapshotKey
    {
        return DependencySnapshotKey::ofLockInputs($this->files, $this->platform);
    }
}
