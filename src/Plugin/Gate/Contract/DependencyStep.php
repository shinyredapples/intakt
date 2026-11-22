<?php

declare(strict_types=1);

namespace Intakt\Plugin\Gate\Contract;

// The locked install apart from the files made from the tree, so heads that share the lock inputs could share one install.
final readonly class DependencyStep
{
    /**
     * @param non-empty-list<string> $lockFiles
     * @param string                 $checkInstall the install among the gate's checkSteps(), which a check that copied a snapshot runs $generate in place of
     */
    public function __construct(
        public array $lockFiles,
        public string $platformProbe,
        public string $install,
        public string $generate,
        public string $checkInstall,
    ) {
    }
}
