<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use DirectoryIterator;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobOutputFile;
use Intakt\Core\Job\Contract\JobOutputRefused;
use SplFileInfo;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use UnexpectedValueException;

// One directory per job, readable by the orchestrator's user only and mounted into that job alone, so no other job can read it.
final readonly class JobOutputDirectories
{
    // Bounded before anything is read, because a job's memory limit does not cover what it writes to the host and the orchestrator holds the output in memory.
    public const int MAX_BYTES = 16 * 1024 * 1024;
    public const int MAX_FILES = 1000;

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/jobs')]
        private string $root,
    ) {
    }

    // A directory left by a crashed run of the same id is removed first, so the job starts empty.
    public function prepare(JobId $job): string
    {
        $directory = $this->of($job);
        $this->remove($job);
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory, 0o700);
        $filesystem->chmod($directory, 0o700);

        return $directory;
    }

    public function read(string $directory): JobOutput
    {
        try {
            $entries = new DirectoryIterator($directory);
        } catch (UnexpectedValueException) {
            throw JobOutputRefused::unlisted();
        }

        $files = [];
        $bytes = 0;

        foreach ($entries as $entry) {
            if ($this->isNoOutput($entry)) {
                continue;
            }

            if (self::MAX_FILES === count($files)) {
                throw JobOutputRefused::tooMany(self::MAX_FILES);
            }

            $bytes += $this->sizeOf($entry);

            if ($bytes > self::MAX_BYTES) {
                throw JobOutputRefused::tooLarge($entry->getFilename(), self::MAX_BYTES);
            }

            $files[$entry->getFilename()] = $this->contentsOf($entry);
        }

        ksort($files, SORT_STRING);

        // A name of digits became an integer key above, so each name is turned back into text.
        return new JobOutput(...array_map(static fn (int|string $name, string $contents): JobOutputFile => new JobOutputFile((string) $name, $contents), array_keys($files), $files));
    }

    public function remove(JobId $job): void
    {
        $directory = $this->of($job);
        $this->makeRemovable($directory);
        new Filesystem()->remove($directory);
    }

    /**
     * @return list<JobId>
     */
    public function leftOver(): array
    {
        $names = is_dir($this->root) ? scandir($this->root) : [];

        return array_values(array_map(
            static fn (string $name): JobId => new JobId($name),
            array_filter(false === $names ? [] : $names, JobId::isValid(...)),
        ));
    }

    private function of(JobId $job): string
    {
        return $this->root.'/'.$job->value;
    }

    private function isNoOutput(DirectoryIterator $entry): bool
    {
        return $entry->isDot();
    }

    // The job wrote this directory, so a link could point the orchestrator at a file of its own.
    private function sizeOf(SplFileInfo $entry): int
    {
        if ($entry->isLink() || !$entry->isFile()) {
            throw JobOutputRefused::notAPlainFile($entry->getFilename());
        }

        return $entry->getSize();
    }

    private function contentsOf(SplFileInfo $entry): string
    {
        $contents = file_get_contents($entry->getPathname());

        return false === $contents ? throw JobOutputRefused::unreadable($entry->getFilename()) : $contents;
    }

    // A job can leave a folder without any permission, which would stop its own cleanup and every later sweep; chmod follows a link, so a link it left is never touched.
    private function makeRemovable(string $path): void
    {
        if (is_link($path) || !is_dir($path)) {
            return;
        }

        try {
            new Filesystem()->chmod($path, 0o700);
        } catch (IOException) {
            // Not the orchestrator's own folder, so nothing can be restored here and the removal reports it.
            return;
        }

        foreach (new DirectoryIterator($path) as $child) {
            if (!$child->isDot()) {
                $this->makeRemovable($child->getPathname());
            }
        }
    }
}
