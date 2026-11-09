<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\JobServices;
use Intakt\Core\Job\Contract\JobSpec;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// The sidecar is the only member of a job's network with a way out; it forwards the allowlisted hosts and logs a refusal for any other (docker/job-proxy).
final readonly class EgressProxy
{
    public const string ALIAS = 'proxy';
    public const int PORT = 8888;

    private const string REFUSAL_MARKER = 'refused on filtered domain';
    private const int MAX_REFUSALS = 100;
    private const int MAX_LINE = 300;
    private const int MEMORY_BYTES = 128 * 1024 * 1024;

    public function __construct(
        #[Autowire(param: JobServices::PROXY_IMAGE)]
        private string $image,
    ) {
    }

    public function address(): string
    {
        return sprintf('http://%s:%d', self::ALIAS, self::PORT);
    }

    /**
     * @return array<string, mixed>
     */
    public function containerBody(JobSpec $spec): array
    {
        return [
            'Image' => $this->image,
            'User' => '65534:65534',
            'Env' => ['INTAKT_PROXY_ALLOW='.implode(',', $spec->allowedHosts())],
            'Labels' => [JobServices::JOB_LABEL => $spec->id],
            'HostConfig' => [
                'NetworkMode' => 'bridge',
                'ReadonlyRootfs' => true,
                'Tmpfs' => ['/tmp' => 'rw,noexec,nosuid,size=16m'],
                'CapDrop' => ['ALL'],
                'SecurityOpt' => ['no-new-privileges'],
                'Memory' => self::MEMORY_BYTES,
                'MemorySwap' => self::MEMORY_BYTES,
                'PidsLimit' => 128,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function refusalsIn(string $log): array
    {
        $refusals = [];

        foreach (explode("\n", $log) as $line) {
            if (str_contains($line, self::REFUSAL_MARKER)) {
                $refusals[] = substr(trim($line), 0, self::MAX_LINE);
            }
        }

        return array_slice($refusals, 0, self::MAX_REFUSALS);
    }
}
