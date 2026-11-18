<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

use Closure;
use Intakt\Core\Job\Infrastructure\ImageBuild;
use PharData;
use Symfony\Component\Filesystem\Filesystem;

final readonly class BuildContext
{
    public function __construct(private ProjectFiles $files)
    {
    }

    /**
     * @param Closure(resource): ImageBuild $send
     */
    // As a tar streamed from a temporary file, because the engine takes the build context as one in the request body and a large repository need not fit in memory.
    public function sentWith(JobImagePlan $plan, string $directory, Closure $send): ImageBuild
    {
        // A name that does not exist yet, because PharData refuses an empty file as a broken archive.
        $file = sys_get_temp_dir().'/intakt-image-'.bin2hex(random_bytes(8)).'.tar';
        $filesystem = new Filesystem();
        $stream = null;

        try {
            $this->write($plan, $directory, $file);
            $stream = fopen($file, 'r');

            if (false === $stream) {
                throw JobImageNotBuilt::contextUnreadable($file);
            }

            return $send($stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }

            $filesystem->remove($file);
        }
    }

    private function write(JobImagePlan $plan, string $directory, string $file): void
    {
        if (null === $plan->tree) {
            new PharData($file)->addFromString($plan->dockerfile, (string) $plan->generated);

            return;
        }

        if (null === $plan->generated) {
            $this->files->archive($directory, $plan->tree, $file);

            return;
        }

        $this->files->archiveAdding($directory, $plan, $file);
    }
}
