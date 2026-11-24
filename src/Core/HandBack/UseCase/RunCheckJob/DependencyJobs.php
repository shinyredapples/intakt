<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunCheckJob;

use Intakt\Core\HandBack\Infrastructure\DependencySnapshots;
use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\EgressHosts;
use Intakt\Core\Job\Contract\JobCause;
use Intakt\Core\Job\Contract\JobCommand;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobEventNotRecorded;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobKeptFolder;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobOutputRefused;
use Intakt\Core\Job\Contract\JobRefused;
use Intakt\Core\Job\Contract\JobResult;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Plugin\Gate\Contract\DependencyStep;
use Intakt\Plugin\Gate\Contract\Gate;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;

// The two jobs around a reused install; neither runs a file of the checked tree other than its lock files, so what is saved holds only what the locked install wrote.
final readonly class DependencyJobs
{
    public const string LOCK = '/input/lock';

    public const string PLATFORM = 'platform';

    public const string RESULT = 'install-result';

    private const string PROBE = 'sh -c "$1" > "${INTAKT_JOB_OUTPUT:-/output}/'.self::PLATFORM.'" < /dev/null';

    // Printable and short, because the version becomes part of the key and the job's output is data.
    private const string WELL_FORMED_PLATFORM = '/\A[\x21-\x7e]{1,64}\z/D';

    private const string INSTALL_LOCK = 'intakt-dependency-install-';

    private const int PROBE_MEMORY_BYTES = 256 * 1024 ** 2;

    private const int PROBE_SECONDS = 60;

    // A check's own limits, because the install is the part of a check it replaces.
    private const int INSTALL_MEMORY_BYTES = 4 * 1024 ** 3;

    private const float CPUS = 2.0;

    private const int INSTALL_SECONDS = 1800;

    public function __construct(
        private JobRunner $jobs,
        private Gate $gate,
        private DependencySnapshots $snapshots,
        private Filesystem $filesystem,
        private LockFactory $locks,
        #[Autowire('%kernel.project_dir%/docker/job/install.sh')]
        private string $installScript,
        #[Autowire('%kernel.project_dir%/var/dependency-installs')]
        private string $installs,
    ) {
    }

    /**
     * @throws DockerEngineFailed
     * @throws JobEventNotRecorded
     */
    public function platformIn(string $image, DependencyStep $step, string $head): ?string
    {
        $spec = new JobSpec(
            new JobId('probe-'.substr($head, 0, 40)),
            $image,
            new JobCommand('/bin/sh', '-c', self::PROBE, 'intakt-probe', $step->platformProbe),
            [],
            new JobLimits(self::PROBE_MEMORY_BYTES, self::CPUS, self::PROBE_SECONDS),
            JobEgress::none(),
        );

        try {
            return $this->platformOf($this->jobs->run($spec));
        } catch (JobOutputRefused|JobRefused) {
            // Any refusal of the probe only costs the reuse, so the check installs itself.
            return null;
        }
    }

    /**
     * @throws DockerEngineFailed
     * @throws JobEventNotRecorded
     */
    public function installed(LockInputs $inputs, DependencyStep $step, string $image): bool
    {
        $key = $inputs->key();
        // Held from the folder's first touch to its removal, because a second check of the same key would otherwise remove the folders the running install fills and saves.
        $lock = $this->locks->createLock(self::INSTALL_LOCK.$key->value, ttl: null);

        if (!$lock->acquire()) {
            // Busy: another check installs the same key and saves it, so this one installs itself.
            return false;
        }

        // Released on its own, so a folder that cannot be removed never keeps the key locked.
        try {
            return $this->installedIn($inputs, $step, $image);
        } finally {
            $lock->release();
        }
    }

    /**
     * @throws DockerEngineFailed
     * @throws JobEventNotRecorded
     */
    private function installedIn(LockInputs $inputs, DependencyStep $step, string $image): bool
    {
        $folder = $this->installs.'/'.$inputs->key()->value;
        $installed = false;

        try {
            $this->prepare($folder, $inputs);
            $result = $this->ranInstall($this->installSpec($folder, $step, $image));
            $installed = $result instanceof JobResult && $this->keptFor($result, $inputs->platform) && $this->saved($inputs, $folder.'/kept');
        } catch (IOException) {
            // An install folder that cannot be made or read only costs the reuse, so the check installs its dependencies itself.
        } finally {
            $removed = $this->removed($folder);
        }

        // A saved snapshot stays in the store for the next check, but this one installs itself, because an install folder it could not remove is a folder error like any other.
        return $installed && $removed;
    }

    private function platformOf(JobResult $result): ?string
    {
        $platform = $result->output->contents(self::PLATFORM) ?? '';

        if (JobCause::Exited !== $result->end->cause || 0 !== $result->end->exitCode || 1 !== preg_match(self::WELL_FORMED_PLATFORM, $platform)) {
            return null;
        }

        return $platform;
    }

    // A leftover of a crashed run under the same key is removed first, so the install starts empty; the key's lock makes it no running install's folder.
    private function prepare(string $folder, LockInputs $inputs): void
    {
        $this->filesystem->remove($folder);
        $this->filesystem->mkdir([$folder.'/lock', $folder.'/kept'], 0o700);

        foreach ($inputs->files as $path => $bytes) {
            $this->filesystem->dumpFile($folder.'/lock/'.$path, $bytes);
        }
    }

    private function installSpec(string $folder, DependencyStep $step, string $image): JobSpec
    {
        $hosts = $this->gate->registryHosts();

        return new JobSpec(
            new JobId('install-'.substr(basename($folder), 0, 40)),
            $image,
            new JobCommand('/bin/sh', '-c', $this->filesystem->readFile($this->installScript), 'intakt-install', self::LOCK, $step->platformProbe, $step->install, ...$step->lockFiles),
            [new JobInput($folder.'/lock', self::LOCK)],
            new JobLimits(self::INSTALL_MEMORY_BYTES, self::CPUS, self::INSTALL_SECONDS),
            [] === $hosts ? JobEgress::none() : JobEgress::to(new EgressHosts(...$hosts)),
            new JobKeptFolder($folder.'/kept'),
        );
    }

    private function ranInstall(JobSpec $spec): ?JobResult
    {
        try {
            return $this->jobs->run($spec);
        } catch (JobOutputRefused|JobRefused) {
            // Refused as already running too, because an orchestrator without the shared lock store could still install the same key.
            return null;
        }
    }

    // The platform is compared again, because the image could have changed between the probe and the install.
    private function keptFor(JobResult $result, string $platform): bool
    {
        return JobCause::Exited === $result->end->cause
            && 0 === $result->end->exitCode
            && "installed\n" === $result->output->contents(self::RESULT)
            && $platform === $this->platformOf($result);
    }

    private function saved(LockInputs $inputs, string $install): bool
    {
        try {
            $this->snapshots->save($inputs->key(), $install);

            return true;
        } catch (IOException) {
            // The store refuses an install it cannot keep whole and read-only, a link out of it included, so the check installs itself.
            return false;
        }
    }

    private function removed(string $folder): bool
    {
        try {
            $this->filesystem->remove($folder);

            return true;
        } catch (IOException) {
            // Not thrown, because the next install of the key removes the leftover in its prepare and this check only loses the reuse.
            return false;
        }
    }
}
