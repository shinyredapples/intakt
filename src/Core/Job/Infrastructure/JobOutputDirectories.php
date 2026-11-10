<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobOutput;
use Intakt\Core\Job\Contract\JobOutputRefused;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

// One directory per job, readable by the orchestrator's user only, and mounted into that job alone.
final readonly class JobOutputDirectories
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/jobs')]
        private string $root,
    ) {
    }

    // A directory left by a crashed run of the same id is removed first, so the job starts empty.
    public function prepare(JobId $job): string
    {
        $directory = $this->of($job);
        $filesystem = new Filesystem();
        $filesystem->remove($directory);
        $filesystem->mkdir($directory, 0o700);
        $filesystem->chmod($directory, 0o700);

        return $directory;
    }

    public function read(string $directory): JobOutput
    {
        $names = scandir($directory);

        if (false === $names) {
            throw JobOutputRefused::unlisted();
        }

        $files = [];

        foreach (array_diff($names, ['.', '..']) as $name) {
            $files[$name] = $this->contentsOf($directory.'/'.$name, $name);
        }

        return new JobOutput($files);
    }

    public function remove(JobId $job): void
    {
        new Filesystem()->remove($this->of($job));
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

    // The job wrote this directory, so a link could point the orchestrator at a file of its own.
    private function contentsOf(string $path, string $name): string
    {
        if (is_link($path) || !is_file($path)) {
            throw JobOutputRefused::notAPlainFile($name);
        }

        $contents = file_get_contents($path);

        return false === $contents ? throw JobOutputRefused::unreadable($name) : $contents;
    }
}
