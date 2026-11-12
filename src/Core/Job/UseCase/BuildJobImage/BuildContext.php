<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

use PharData;
use Symfony\Component\Filesystem\Filesystem;

final readonly class BuildContext
{
    public function __construct(private ProjectFiles $files)
    {
    }

    // As a tar, because the engine takes the build context as one in the request body.
    public function of(JobImagePlan $plan, string $directory): string
    {
        // A name that does not exist yet, because PharData refuses an empty file as a broken archive.
        $file = sys_get_temp_dir().'/intakt-image-'.bin2hex(random_bytes(8)).'.tar';
        $filesystem = new Filesystem();

        try {
            $this->write($plan, $directory, $file);

            return $filesystem->readFile($file);
        } finally {
            $filesystem->remove($file);
        }
    }

    private function write(JobImagePlan $plan, string $directory, string $file): void
    {
        if (null === $plan->generated) {
            $this->files->archive($directory, $plan->tree, $file);

            return;
        }

        new PharData($file)->addFromString($plan->dockerfile, $plan->generated);
    }
}
