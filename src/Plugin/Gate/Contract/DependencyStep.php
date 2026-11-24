<?php

declare(strict_types=1);

namespace Intakt\Plugin\Gate\Contract;

// The locked install apart from the files made from the tree, so heads that share the lock inputs could share one install; a check that copied one swaps checkInstall for generate.
final readonly class DependencyStep
{
    public function __construct(
        public LockFiles $lockFiles,
        public string $platformProbe,
        public string $install,
        public string $generate,
        public string $checkInstall,
    ) {
    }
}
