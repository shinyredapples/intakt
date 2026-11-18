<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

use Symfony\Component\Filesystem\Path;

// Mounted read-only, and never over the work area, the output directory or the kept directory, so an input cannot hide any of them.
final readonly class JobInput
{
    public function __construct(
        public string $hostPath,
        public string $containerPath,
    ) {
        if (!Path::isAbsolute($hostPath) || !$this->isFreeContainerPath($containerPath)) {
            throw JobRefused::forInput($hostPath, $containerPath);
        }
    }

    private function isFreeContainerPath(string $path): bool
    {
        if (!str_starts_with($path, '/')) {
            return false;
        }

        $canonical = Path::canonicalize($path);

        if ('/' === $canonical) {
            return false;
        }

        return array_all([JobSpec::WORK_AREA, JobSpec::OUTPUT_DIRECTORY, JobSpec::KEPT_DIRECTORY], static fn (string $writable): bool => !Path::isBasePath($canonical, $writable) && !Path::isBasePath($writable, $canonical));
    }
}
