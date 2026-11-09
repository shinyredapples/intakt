<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\EgressPolicy;
use Intakt\Core\Job\Contract\JobInput;
use Intakt\Core\Job\Contract\JobPaths;
use Intakt\Core\Job\Contract\JobServices;
use Intakt\Core\Job\Contract\JobSpec;

// The environment is built from this list only and never inherited, so no forge token, signing key or vendor API key of the orchestrator reaches a job.
final readonly class JobContainerBody
{
    private const int WORK_AREA_BYTES = 1024 * 1024 * 1024;
    private const int PIDS = 512;
    private const string START_CHECK = __DIR__.'/start-check.sh';

    public function __construct(
        private EgressProxy $proxy,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function of(JobSpec $spec, string $network): array
    {
        $script = file_get_contents(self::START_CHECK);

        if (false === $script) {
            throw new DockerEngineError('The start check script cannot be read.');
        }

        return [
            'Image' => $spec->image,
            'Entrypoint' => ['sh', '-c', $script, 'intakt-job'],
            'Cmd' => $spec->command,
            'User' => getmyuid().':'.getmygid(),
            'WorkingDir' => JobPaths::WORK,
            'Env' => $this->environment($spec),
            'Labels' => [JobServices::JOB_LABEL => $spec->id],
            'HostConfig' => [
                'NetworkMode' => $network,
                'ReadonlyRootfs' => true,
                'Mounts' => $this->mounts($spec),
                'Tmpfs' => $this->tmpfs(),
                'Memory' => $spec->memoryBytes,
                'MemorySwap' => $spec->memoryBytes,
                'NanoCpus' => (int) round($spec->cpus * 1_000_000_000),
                'PidsLimit' => self::PIDS,
                'CapDrop' => ['ALL'],
                'SecurityOpt' => ['no-new-privileges'],
                'Privileged' => false,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function environment(JobSpec $spec): array
    {
        $environment = [
            'HOME='.JobPaths::HOME,
            'INTAKT_JOB_ID='.$spec->id,
            'INTAKT_OUTPUT_DIR='.JobPaths::OUTPUT,
            'INTAKT_INPUTS='.implode("\n", array_map(static fn (JobInput $input): string => $input->containerPath, $spec->inputs)),
        ];

        if (EgressPolicy::None === $spec->egress) {
            return $environment;
        }

        $proxy = $this->proxy->address();

        return [...$environment, 'INTAKT_VENDOR_HOST='.$spec->vendorHost, 'HTTPS_PROXY='.$proxy, 'https_proxy='.$proxy, 'HTTP_PROXY='.$proxy, 'http_proxy='.$proxy];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mounts(JobSpec $spec): array
    {
        $mounts = array_map(
            static fn (JobInput $input): array => ['Type' => 'bind', 'Source' => $input->hostPath, 'Target' => $input->containerPath, 'ReadOnly' => true],
            $spec->inputs,
        );
        // The one writable bind: the job's own output directory, never another job's.
        $mounts[] = ['Type' => 'bind', 'Source' => $spec->outputDir, 'Target' => JobPaths::OUTPUT, 'ReadOnly' => false];

        return $mounts;
    }

    /**
     * @return array<string, string>
     */
    private function tmpfs(): array
    {
        $options = 'rw,exec,nosuid,nodev,size='.self::WORK_AREA_BYTES;

        // A fresh home and work area per job, gone with the container.
        return [JobPaths::WORK => $options, JobPaths::HOME => $options, JobPaths::TMP => $options];
    }
}
