<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobServices;
use Intakt\Core\Job\Contract\JobSpec;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

// Writable only in the tmpfs work area and the job's own output directory, so nothing else a job writes survives it.
final readonly class JobContainerDefinition
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/docker/job/start-check.sh')]
        private string $startCheckScript,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function of(JobSpec $spec, string $outputDirectory, EgressRoute $route): array
    {
        return [
            'Image' => $spec->image,
            // Passed inline rather than mounted, so the check runs in any image with a POSIX shell and no input path can collide with it.
            'Entrypoint' => ['/bin/sh', '-c', new Filesystem()->readFile($this->startCheckScript), 'intakt-start-check'],
            'Cmd' => [...array_map(static fn (JobInput $input): string => $input->containerPath, $spec->inputs), '--', ...$spec->command],
            // The orchestrator's own user, so the job can write the output directory only it may read, and never runs as root.
            'User' => sprintf('%d:%d', posix_getuid(), posix_getgid()),
            // A home and caches on the work area, so every job starts with a wiped home.
            'Env' => [...$this->environmentOf($spec), ...$route->environment],
            'WorkingDir' => JobSpec::WORK_AREA,
            'Labels' => [JobServices::JOB_LABEL => $spec->id->value],
            'NetworkDisabled' => $route->isNone(),
            'HostConfig' => $this->hostConfigOf($spec, $outputDirectory, $route),
        ];
    }

    /**
     * @return list<string>
     */
    private function environmentOf(JobSpec $spec): array
    {
        $environment = ['HOME='.JobSpec::WORK_AREA, 'TMPDIR='.JobSpec::WORK_AREA, 'INTAKT_JOB_ID='.$spec->id->value, 'INTAKT_JOB_OUTPUT='.JobSpec::OUTPUT_DIRECTORY];

        return $spec->egress->isNone() ? $environment : [...$environment, 'INTAKT_JOB_EGRESS='.implode(' ', $spec->egress->hosts)];
    }

    /**
     * @return array<string, mixed>
     */
    private function hostConfigOf(JobSpec $spec, string $outputDirectory, EgressRoute $route): array
    {
        return [
            'ReadonlyRootfs' => true,
            'Tmpfs' => [JobSpec::WORK_AREA => 'rw,nosuid,nodev'],
            'Mounts' => [...array_map($this->inputMountOf(...), $spec->inputs), $this->outputMountOf($outputDirectory)],
            // Swap equal to memory, so a runaway job is killed at its limit instead of swapping.
            'Memory' => $spec->limits->memoryBytes,
            'MemorySwap' => $spec->limits->memoryBytes,
            'NanoCpus' => $spec->limits->nanoCpus,
            // No network, or the job's internal network whose only way out is its own proxy, so a job reaches nothing by default.
            'NetworkMode' => $route->networkMode,
            'CapDrop' => ['ALL'],
            'SecurityOpt' => ['no-new-privileges'],
        ];
    }

    /**
     * @return array{Type: string, Source: string, Target: string, ReadOnly: bool}
     */
    private function inputMountOf(JobInput $input): array
    {
        return ['Type' => 'bind', 'Source' => $input->hostPath, 'Target' => $input->containerPath, 'ReadOnly' => true];
    }

    /**
     * @return array{Type: string, Source: string, Target: string, ReadOnly: bool}
     */
    private function outputMountOf(string $outputDirectory): array
    {
        return ['Type' => 'bind', 'Source' => $outputDirectory, 'Target' => JobSpec::OUTPUT_DIRECTORY, 'ReadOnly' => false];
    }
}
