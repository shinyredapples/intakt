<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\BuildJobImage;

use Closure;
use LogicException;
use PharData;
use SplFileObject;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

final readonly class BuildContext
{
    private const string FOLDER_PREFIX = 'intakt-image-';
    private const string FILE_NAME = 'context.tar';

    public function __construct(private ProjectFiles $files)
    {
    }

    // As a tar in a folder only the owner can enter, because the engine takes the build context as one in the request body and the file is streamed from there.
    public function of(JobImagePlan $plan, string $directory): string
    {
        $folder = $this->temporaryFolder().'/'.self::FOLDER_PREFIX.bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->mkdir($folder, 0o700);
        // A name that does not exist yet, because PharData refuses an empty file as a broken archive.
        $file = $folder.'/'.self::FILE_NAME;

        try {
            $this->write($plan, $directory, $file);
            // Boundary: removes the half-written context folder on any failure and rethrows it, because nothing else knows the folder to clean it up.
        } catch (Throwable $failure) {
            $filesystem->remove($folder);

            throw $failure;
        }

        return $file;
    }

    /**
     * @return Closure(int): string
     */
    // Read in chunks and removed at its end, so the archive leaves the disk as soon as the engine has it rather than when the build ends, up to an hour later.
    public function upload(string $file): Closure
    {
        $this->refuseAnythingButAContext($file);
        $reader = new SplFileObject($file, 'rb');

        return function (int $size) use ($file, &$reader): string {
            $chunk = $reader instanceof SplFileObject ? $reader->fread($size) : '';

            if (false === $chunk || '' === $chunk) {
                $reader = null;
                $this->remove($file);

                return '';
            }

            return $chunk;
        };
    }

    public function remove(string $file): void
    {
        $this->refuseAnythingButAContext($file);
        new Filesystem()->remove(dirname($file));
    }

    // Because removing deletes the whole folder, a path that is not a context written here would take an unrelated folder with it.
    private function refuseAnythingButAContext(string $file): void
    {
        $folder = dirname($file);

        if (self::FILE_NAME !== basename($file) || $this->temporaryFolder().'/'.basename($folder) !== $folder || !str_starts_with(basename($folder), self::FOLDER_PREFIX)) {
            throw new LogicException(sprintf('"%s" is not a build context.', $file));
        }
    }

    // Without trailing slashes, because PHP strips only one of them from a configured temporary directory and the guard compares the path this class built itself.
    private function temporaryFolder(): string
    {
        return rtrim(sys_get_temp_dir(), '/');
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
