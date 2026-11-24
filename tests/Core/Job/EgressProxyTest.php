<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Contract\EgressHosts;
use Intakt\Core\Job\Contract\JobCommand;
use Intakt\Core\Job\Contract\JobEgress;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobLimits;
use Intakt\Core\Job\Contract\JobSpec;
use Intakt\Core\Job\Infrastructure\EgressLog;
use Intakt\Core\Job\Infrastructure\EgressProxyDefinition;
use Intakt\Core\Job\Infrastructure\EgressRefusal;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EgressProxyDefinition::class)]
#[CoversClass(EgressLog::class)]
#[UsesClass(EgressRefusal::class)]
#[UsesClass(JobEgress::class)]
#[UsesClass(JobId::class)]
#[UsesClass(JobLimits::class)]
#[UsesClass(JobSpec::class)]
final class EgressProxyTest extends TestCase
{
    // Lines in Squid's native access log format, so the tests read what the proxy really writes.
    private const string REFUSED_TUNNEL = '1762855200.123      0 192.0.2.3 TCP_DENIED/403 3924 CONNECT outside.example:443 - HIER_NONE/- text/html';
    private const string REFUSED_PLAIN_REQUEST = '1762855201.456      1 192.0.2.3 TCP_DENIED/403 3968 GET http://outside.example/upload - HIER_NONE/- text/html';
    private const string REFUSED_PORT = '1762855202.789      0 192.0.2.3 TCP_DENIED/403 3924 CONNECT api.vendor.example:22 - HIER_NONE/- text/html';
    private const string IMAGE_DIRECTORY = __DIR__.'/../../../docker/egress-proxy';
    private const string TUNNEL = '1762855203.012  45210 192.0.2.3 TCP_TUNNEL/200 6143 CONNECT api.vendor.example:443 - HIER_DIRECT/api.vendor.example -';

    public function testTheProxyAllowsOnlyTlsTunnelsToTheAllowlistedHosts(): void
    {
        $config = $this->configFor(JobEgress::to(new EgressHosts('api.vendor.example', 'repo.packagist.org')));

        self::assertContains('acl intakt_allowed dstdomain -n api.vendor.example repo.packagist.org', $config);
        self::assertSame(['http_access allow intakt_connect intakt_tls intakt_allowed', 'http_access deny all'], $this->linesStartingWith('http_access ', $config));
        self::assertContains('acl intakt_tls port 443', $config);
        self::assertContains('acl intakt_connect method CONNECT', $config);
    }

    public function testTheProxyDeniesEverythingElseByDefault(): void
    {
        $access = $this->linesStartingWith('http_access ', $this->configFor(JobEgress::to(new EgressHosts('api.vendor.example'))));

        self::assertSame('http_access deny all', array_last($access));
        self::assertCount(1, array_filter($access, static fn (string $line): bool => str_starts_with($line, 'http_access allow ')));
    }

    public function testTheProxyLogsEveryRequestItDoesNotAllowAndNoOther(): void
    {
        $config = $this->configFor(JobEgress::to(new EgressHosts('api.vendor.example')));

        self::assertSame(['access_log none intakt_connect intakt_tls intakt_allowed', 'access_log none localhost', 'access_log stdio:/dev/stdout logformat=squid'], $this->linesStartingWith('access_log ', $config));
    }

    public function testTheProxyIsHealthyOnlyOnceItAcceptsConnectionsOnItsPort(): void
    {
        $definition = new EgressProxyDefinition('intakt-egress-proxy')->of($this->spec(JobEgress::to(new EgressHosts('api.vendor.example'))), 'intakt-job-build-1');

        self::assertSame([
            'Test' => ['CMD', 'bash', '-c', 'exec 3<>/dev/tcp/127.0.0.1/3128'],
            'Interval' => 30_000_000_000,
            'Timeout' => 1_000_000_000,
            'Retries' => 3,
            'StartPeriod' => 30_000_000_000,
            'StartInterval' => 250_000_000,
        ], $definition['Healthcheck']);
    }

    public function testTheProxysEntrypointIsExecutable(): void
    {
        self::assertTrue(is_executable(self::IMAGE_DIRECTORY.'/entrypoint.sh'));
    }

    public function testTheProxyImageKeepsItsEntrypointExecutable(): void
    {
        self::assertStringContainsString("\nCOPY --chmod=0755 entrypoint.sh /usr/local/bin/intakt-egress-proxy\n", (string) file_get_contents(self::IMAGE_DIRECTORY.'/Dockerfile'));
    }

    public function testTheProxyRunsReadOnlyWithoutCapabilitiesOnTheJobsNetworkAndTheBridge(): void
    {
        $definition = new EgressProxyDefinition('intakt-egress-proxy')->of($this->spec(JobEgress::to(new EgressHosts('api.vendor.example'))), 'intakt-job-build-1');

        self::assertSame(['intakt.job' => 'build-1'], $definition['Labels']);
        self::assertSame([
            'ReadonlyRootfs' => true,
            'Tmpfs' => ['/run/squid' => 'rw,nosuid,nodev,noexec'],
            'Memory' => 256 * 1024 * 1024,
            'MemorySwap' => 256 * 1024 * 1024,
            'NetworkMode' => 'bridge',
            'CapDrop' => ['ALL'],
            'SecurityOpt' => ['no-new-privileges'],
        ], $definition['HostConfig']);
        self::assertEquals(['EndpointsConfig' => ['bridge' => (object) [], 'intakt-job-build-1' => ['Aliases' => ['egress-proxy']]]], $definition['NetworkingConfig']);
    }

    public function testARefusedTunnelIsReadFromTheProxysLog(): void
    {
        self::assertEquals([new EgressRefusal('CONNECT', 'outside.example:443')], new EgressLog()->refusalsIn(self::REFUSED_TUNNEL.PHP_EOL));
    }

    public function testARefusedPlainRequestAndATunnelToAnotherPortAreReadToo(): void
    {
        $refusals = new EgressLog()->refusalsIn(self::REFUSED_PLAIN_REQUEST.PHP_EOL.self::REFUSED_PORT.PHP_EOL);

        self::assertEquals([new EgressRefusal('GET', 'http://outside.example/upload'), new EgressRefusal('CONNECT', 'api.vendor.example:22')], $refusals);
    }

    public function testAnAllowedTunnelAndALineOfAnotherShapeAreNoRefusal(): void
    {
        $log = self::TUNNEL.PHP_EOL.'2026/11/11 10:00:00| WARNING: TCP_DENIED/403 CONNECT outside.example:443 '.PHP_EOL;

        self::assertSame([], new EgressLog()->refusalsIn($log));
    }

    public function testARepeatedRefusalIsReadOnce(): void
    {
        $refusals = new EgressLog()->refusalsIn(str_repeat(self::REFUSED_TUNNEL.PHP_EOL, 3));

        self::assertEquals([new EgressRefusal('CONNECT', 'outside.example:443')], $refusals);
    }

    public function testAtMostTwentyDistinctRefusalsAreRead(): void
    {
        $lines = array_map(static fn (int $n): string => str_replace('outside.example', sprintf('host%d.example', $n), self::REFUSED_TUNNEL), range(1, EgressLog::MAX_REFUSALS + 1));

        $refusals = new EgressLog()->refusalsIn(implode(PHP_EOL, $lines).PHP_EOL);

        self::assertCount(EgressLog::MAX_REFUSALS, $refusals);
        self::assertSame('host20.example:443', array_last($refusals)?->target);
    }

    public function testALongTargetIsCutTo255Bytes(): void
    {
        $long = 'http://outside.example/'.str_repeat('a', 300);

        $refusals = new EgressLog()->refusalsIn(str_replace('http://outside.example/upload', $long, self::REFUSED_PLAIN_REQUEST).PHP_EOL);

        self::assertSame(substr($long, 0, 255), array_first($refusals)?->target);
    }

    /**
     * @return list<string>
     */
    private function configFor(JobEgress $egress): array
    {
        return explode(PHP_EOL, new EgressProxyDefinition('intakt-egress-proxy')->squidConfigOf($egress));
    }

    /**
     * @param list<string> $config
     *
     * @return list<string>
     */
    private function linesStartingWith(string $prefix, array $config): array
    {
        return array_values(array_filter($config, static fn (string $line): bool => str_starts_with($line, $prefix)));
    }

    private function spec(JobEgress $egress): JobSpec
    {
        return new JobSpec(new JobId('build-1'), 'sample:latest', new JobCommand('true'), [], new JobLimits(512 * 1024 * 1024, 1.0, 60), $egress);
    }
}
