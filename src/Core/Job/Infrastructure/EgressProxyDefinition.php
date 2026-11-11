<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobServices;
use Intakt\Core\Job\Contract\JobSpec;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// The whole Squid config travels in the proxy's environment, so the image holds no policy that could drift from this one.
final readonly class EgressProxyDefinition
{
    public const string ALIAS = 'egress-proxy';
    public const int PORT = 3128;

    // The engine's default network, so the proxy, and only the proxy, has a route out of the job's internal network.
    private const string OUTSIDE = 'bridge';
    private const string CONFIG_DIRECTORY = '/run/squid';

    // Fixed, so the traffic of a job cannot grow its proxy, which keeps no disk cache.
    private const int MEMORY_BYTES = 256 * 1024 * 1024;

    public function __construct(
        #[Autowire(param: JobServices::EGRESS_PROXY_IMAGE)]
        private string $image,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function of(JobSpec $spec, string $network): array
    {
        return [
            'Image' => $this->image,
            'Env' => ['INTAKT_EGRESS_SQUID_CONF='.$this->squidConfigOf($spec->egress)],
            'Labels' => [JobServices::JOB_LABEL => $spec->id->value],
            'HostConfig' => [
                'ReadonlyRootfs' => true,
                'Tmpfs' => [self::CONFIG_DIRECTORY => 'rw,nosuid,nodev,noexec'],
                'Memory' => self::MEMORY_BYTES,
                'MemorySwap' => self::MEMORY_BYTES,
                'NetworkMode' => self::OUTSIDE,
                'CapDrop' => ['ALL'],
                'SecurityOpt' => ['no-new-privileges'],
            ],
            'NetworkingConfig' => ['EndpointsConfig' => [self::OUTSIDE => (object) [], $network => ['Aliases' => [self::ALIAS]]]],
        ];
    }

    // Everything but a TLS tunnel to an allowlisted host falls through to deny all, so the proxy denies by default; only denials are logged, so allowed traffic cannot push them out of the log.
    public function squidConfigOf(JobEgress $egress): string
    {
        return implode(PHP_EOL, [
            'http_port '.self::PORT,
            'acl intakt_allowed dstdomain -n '.implode(' ', $egress->hosts),
            'acl intakt_tls port 443',
            'acl intakt_connect method CONNECT',
            'http_access allow intakt_connect intakt_tls intakt_allowed',
            'http_access deny all',
            'access_log none intakt_connect intakt_tls intakt_allowed',
            'access_log stdio:/dev/stdout logformat=squid',
            'cache_log /dev/stderr',
            'cache deny all',
            'pid_filename none',
            'netdb_filename none',
            'coredump_dir '.self::CONFIG_DIRECTORY,
            'visible_hostname '.self::ALIAS,
        ]).PHP_EOL;
    }
}
