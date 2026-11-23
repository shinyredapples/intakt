<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

use Symfony\Component\Filesystem\Path;

// A host folder the caller made and removes, mounted writable at JobSpec::KEPT_FOLDER, so a job can hand back more than its output directory may hold.
final readonly class JobKeptFolder
{
    public function __construct(public string $hostPath)
    {
        if (!Path::isAbsolute($hostPath)) {
            throw JobRefused::forKeptFolder($hostPath);
        }
    }
}
