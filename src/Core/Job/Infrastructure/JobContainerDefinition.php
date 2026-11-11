<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobServices;
use Intakt\Core\Job\Contract\JobSpec;

// Writable only in the tmpfs work area and the job's own output directory, so nothing else a job writes survives it.
final readonly class JobContainerDefinition
{
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
            'Labels' => [JobServices::JOB_LABEL => $spec->id->value],
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
            'Mounts' => [...array_map($this->inputMountOf(...), $spec->inputs), $this->outputMountOf($outputDirectory)],
            // Swap equal to memory, so a runaway job is killed at its limit instead of swapping.
            'Memory' => $spec->limits->memoryBytes,
            'MemorySwap' => $spec->limits->memoryBytes,
            'NanoCpus' => $spec->limits->nanoCpus,
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
