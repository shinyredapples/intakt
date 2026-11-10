<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobSpec;

// The only writable places are the tmpfs work area, which goes with the container, and the job's own output directory.
final readonly class JobContainerDefinition
{
    private const int NANO = 1_000_000_000;

    /**
     * @return array<string, mixed>
     */
    public function of(JobSpec $spec, string $outputDirectory): array
    {
        return [
            'Image' => $spec->image,
            'Cmd' => $spec->command,
            // The orchestrator's own user, so the job can write the output directory only it may read, and never runs as root.
            'User' => sprintf('%d:%d', posix_getuid(), posix_getgid()),
            // A home and caches on the work area, so every job starts with a wiped home.
            'Env' => ['HOME='.JobSpec::WORK_AREA, 'TMPDIR='.JobSpec::WORK_AREA, 'INTAKT_JOB_ID='.$spec->id->value],
            'WorkingDir' => JobSpec::WORK_AREA,
            'Labels' => [DockerEngine::JOB_LABEL => $spec->id->value],
            'NetworkDisabled' => true,
            'HostConfig' => $this->hostConfigOf($spec, $outputDirectory),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function hostConfigOf(JobSpec $spec, string $outputDirectory): array
    {
        return [
            'ReadonlyRootfs' => true,
            'Tmpfs' => [JobSpec::WORK_AREA => 'rw,nosuid,nodev'],
            'Mounts' => [...array_map($this->inputMountOf(...), $spec->inputs), $this->bindOf($outputDirectory, JobSpec::OUTPUT_DIRECTORY, false)],
            // Swap equal to memory, so a runaway job is killed at its limit instead of swapping.
            'Memory' => $spec->limits->memoryBytes,
            'MemorySwap' => $spec->limits->memoryBytes,
            'NanoCpus' => (int) round($spec->limits->cpus * self::NANO),
            // No network until the per-job egress proxy exists, so a job reaches nothing by default.
            'NetworkMode' => 'none',
            'CapDrop' => ['ALL'],
            'SecurityOpt' => ['no-new-privileges'],
        ];
    }

    /**
     * @return array{Type: string, Source: string, Target: string, ReadOnly: bool}
     */
    private function inputMountOf(JobInput $input): array
    {
        return $this->bindOf($input->hostPath, $input->containerPath, true);
    }

    /**
     * @return array{Type: string, Source: string, Target: string, ReadOnly: bool}
     */
    private function bindOf(string $source, string $target, bool $readOnly): array
    {
        return ['Type' => 'bind', 'Source' => $source, 'Target' => $target, 'ReadOnly' => $readOnly];
    }
}
