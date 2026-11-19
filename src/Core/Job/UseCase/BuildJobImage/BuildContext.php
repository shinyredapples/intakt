<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

use PharData;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

final readonly class BuildContext
{
    public function __construct(private ProjectFiles $files)
    {
    }

    // As a tar in a folder only the owner can enter, because the engine takes the build context as one in the request body and the file is streamed from there.
    public function of(JobImagePlan $plan, string $directory): string
    {
        $folder = sys_get_temp_dir().'/intakt-image-'.bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->mkdir($folder, 0o700);
        // A name that does not exist yet, because PharData refuses an empty file as a broken archive.
        $file = $folder.'/context.tar';

        try {
            $this->write($plan, $directory, $file);
        } catch (Throwable $failure) {
            $filesystem->remove($folder);

            throw $failure;
        }

        return $file;
    }

    public function remove(string $file): void
    {
        new Filesystem()->remove(dirname($file));
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
