<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunCheckJob;

use Intakt\Plugin\Gate\Contract\DependencyInstall;

// Everything a dependency install depends on, so two heads share a snapshot exactly when an install would give both the same folder.
final readonly class DependencySource
{
    /**
     * @param array<string, string> $lockFiles
     */
    public function __construct(
        public DependencyInstall $install,
        public string $image,
        public array $lockFiles,
    ) {
    }

    // Each part is length-prefixed, so no file content can be crafted to read as another path or image.
    public function key(): string
    {
        $parts = [$this->install->command, $this->install->directory, $this->image];

        foreach ($this->lockFiles as $path => $contents) {
            $parts[] = $path;
            $parts[] = $contents;
        }

        return hash('sha256', implode('', array_map(static fn (string $part): string => strlen($part).':'.$part, $parts)));
    }
}
