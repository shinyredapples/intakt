<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunCheckJob;

use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutputRefused;
use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Plugin\Gate\Contract\Gate;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

// A job of its own that sees the lock files and nothing of the builder's tree, so no code a builder wrote runs where the snapshot is made.
final readonly class DependencyInstallJob
{
    public const string LOCK_FILES = '/input/lock';

    public const string RESULT = 'install-result';

    private const int MEMORY_BYTES = 2 * 1024 ** 3;

    private const float CPUS = 2.0;

    private const int SECONDS = 900;

    public function __construct(
        private JobRunner $jobs,
        private Gate $gate,
        private Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%/docker/job/install-dependencies.sh')]
        private string $installScript,
    ) {
    }

    /**
     * @throws DockerEngineFailed
     * @throws JobEventNotRecorded
     */
    public function installed(DependencySource $source, string $staging): bool
    {
        $this->writeLockFiles($source, $staging.'/lock');
        $this->filesystem->mkdir($staging.'/kept', 0o755);

        try {
            $result = $this->jobs->run($this->specOf($source, $staging));
        } catch (JobOutputRefused|JobRefused) {
            // Nothing is kept, because an install whose output cannot be read or that did not start has not shown it finished.
            return false;
        }

        return $this->finished($result) && $this->isRealDirectory($staging.'/kept/'.$source->install->directory);
    }

    private function writeLockFiles(DependencySource $source, string $directory): void
    {
        foreach ($source->lockFiles as $path => $contents) {
            $this->filesystem->dumpFile($directory.'/'.$path, $contents);
        }
    }

    private function specOf(DependencySource $source, string $staging): JobSpec
    {
        $hosts = $this->gate->registryHosts();

        return new JobSpec(
            new JobId('dependencies-'.substr($source->key(), 0, 40)),
            $source->image,
            ['/bin/sh', '-c', $this->filesystem->readFile($this->installScript), 'intakt-install-dependencies', self::LOCK_FILES, JobSpec::KEPT_DIRECTORY, $source->install->directory, '--', $source->install->command],
            [new JobInput($staging.'/lock', self::LOCK_FILES)],
            new JobLimits(self::MEMORY_BYTES, self::CPUS, self::SECONDS),
            [] === $hosts ? JobEgress::none() : JobEgress::to($hosts),
            $staging.'/kept',
        );
    }

    private function finished(JobResult $result): bool
    {
        return JobCause::Exited === $result->end->cause && "installed\n" === $result->output->contents(self::RESULT);
    }

    private function isRealDirectory(string $path): bool
    {
        return !is_link($path) && is_dir($path);
    }
}
