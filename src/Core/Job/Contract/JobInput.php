<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

use Symfony\Component\Filesystem\Path;

// Mounted read-only, and never over the work area, the output directory or the kept folder, so an input cannot hide any of them.
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

        return '/' !== $canonical
            && !Path::isBasePath($canonical, JobSpec::WORK_AREA)
            && !Path::isBasePath(JobSpec::WORK_AREA, $canonical)
            && !Path::isBasePath($canonical, JobSpec::OUTPUT_DIRECTORY)
            && !Path::isBasePath(JobSpec::OUTPUT_DIRECTORY, $canonical)
            && !Path::isBasePath($canonical, JobSpec::KEPT_FOLDER)
            && !Path::isBasePath(JobSpec::KEPT_FOLDER, $canonical);
    }
}
