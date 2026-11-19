<?php

declare(strict_types=1);

namespace Intakt\Core\Watcher\Contract;

final readonly class FailingMain
{
    public function __construct(
        public string $project,
        public string $main,
        public string $toolFingerprint,
        public string $reason,
    ) {
    }

    public function isOf(string $main, string $toolFingerprint): bool
    {
        return $main === $this->main && $toolFingerprint === $this->toolFingerprint;
    }
}
