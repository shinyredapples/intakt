<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

// Always mounted read-only; the job's only writable place outside its tmpfs areas is its output directory.
final readonly class JobInput
{
    public function __construct(
        public string $hostPath,
        public string $containerPath,
    ) {
        if (!str_starts_with($hostPath, '/') || !str_starts_with($containerPath, '/')) {
            throw new InvalidJobSpec('An input is mounted from an absolute path to an absolute path.');
        }

        if ($this->shadowsAReservedPath()) {
            throw new InvalidJobSpec(sprintf('An input cannot be mounted at %s, a place the job container uses itself.', $containerPath));
        }
    }

    private function shadowsAReservedPath(): bool
    {
        $target = rtrim($this->containerPath, '/');

        if ('' === $target) {
            return true;
        }

        return array_any(JobPaths::reserved(), fn ($reserved) => str_starts_with($target.'/', $reserved.'/'));
    }
}
