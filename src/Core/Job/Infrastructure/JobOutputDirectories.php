<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use DirectoryIterator;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobOutputFile;
use Intakt\Core\Job\Contract\JobOutputPiece;
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
    public const int TAIL_BYTES = 1024 * 1024;
    private const int FILE_TYPE = 0o170000;
    private const int PLAIN_FILE = 0o100000;

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

    // A followed file is handed back by its tail alone, because a watch read it in pieces while the job ran and it may have grown past the bound.
    public function read(string $directory, ?string $followed = null): JobOutput
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

            $size = $this->sizeOf($entry);
            $skipped = $this->tailStart($entry->getFilename(), $size, $followed);
            $bytes += $size - $skipped;

            if ($bytes > self::MAX_BYTES) {
                throw JobOutputRefused::tooLarge($entry->getFilename(), self::MAX_BYTES);
            }

            $files[$entry->getFilename()] = $this->contentsOf($entry, $skipped);
        }

        ksort($files, SORT_STRING);

        // A name of digits became an integer key above, so each name is turned back into text.
        return new JobOutput(...array_map(static fn (string $name, string $contents): JobOutputFile => new JobOutputFile($name, $contents), array_map(strval(...), array_keys($files)), $files));
    }

    // A piece of a file the running job writes. Opened for reading and writing, because opening for reading alone a pipe the job put in the file's place waits for a writer; then checked to be the plain file that was listed, because the job can swap the entry between the look and the open; never more than the asked bytes, because the file can grow while it is read.
    public function piece(string $path, int $from, int $maxBytes): ?JobOutputPiece
    {
        $handle = $this->openedPlainFile($path);
        $opened = null === $handle ? false : fstat($handle);

        if (null === $handle || false === $opened) {
            return null;
        }

        $bytes = $from < $opened['size'] ? stream_get_contents($handle, min($maxBytes, $opened['size'] - $from), $from) : '';
        fclose($handle);

        return new JobOutputPiece($opened['dev'].':'.$opened['ino'], $opened['size'], false === $bytes ? '' : $bytes);
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

    /**
     * @return resource|null
     */
    private function openedPlainFile(string $path)
    {
        clearstatcache(true, $path);
        $listed = is_link($path) || !is_file($path) ? false : lstat($path);
        // Silenced, because a file the job locked or swapped away fails here, which is an answer, not a fault.
        $handle = false === $listed ? false : @fopen($path, 'r+b');
        $opened = false === $handle ? false : fstat($handle);

        if (!in_array(false, [$handle, $opened, $listed], true) && $this->isTheListedPlainFile($opened, $listed)) {
            return $handle;
        }

        if (false !== $handle) {
            fclose($handle);
        }

        return null;
    }

    /**
     * @param array{dev: int, ino: int, mode: int} $opened
     * @param array{dev: int, ino: int}            $listed
     */
    private function isTheListedPlainFile(array $opened, array $listed): bool
    {
        return self::PLAIN_FILE === ($opened['mode'] & self::FILE_TYPE) && [$opened['dev'], $opened['ino']] === [$listed['dev'], $listed['ino']];
    }

    // The tail only, from the followed file, because the end of a watched job hands back that much of it.
    private function tailStart(string $name, int $size, ?string $followed): int
    {
        return $followed === $name ? max(0, $size - self::TAIL_BYTES) : 0;
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

    private function contentsOf(SplFileInfo $entry, int $skipped): string
    {
        $contents = file_get_contents($entry->getPathname(), false, null, $skipped);

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
