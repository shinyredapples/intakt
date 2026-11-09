<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Job;

use Intakt\Core\Job\Infrastructure\EgressProxy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Intakt\Core\Job\Contract\JobSpec;

#[CoversClass(EgressProxy::class)]
#[UsesClass(JobSpec::class)]
final class EgressProxyTest extends TestCase
{
    public function testAHostOutsideTheAllowlistIsReportedFromTheProxysLog(): void
    {
        $log = "INFO Connect (file descriptor 7): 10.0.0.2\nNOTICE Proxying refused on filtered domain \"evil.example\"\nINFO Closed connection\n";

        self::assertSame(['NOTICE Proxying refused on filtered domain "evil.example"'], new EgressProxy('p')->refusalsIn($log));
    }

    public function testALogWithoutARefusalReportsNone(): void
    {
        self::assertSame([], new EgressProxy('p')->refusalsIn("INFO Connect\nINFO Closed connection\n"));
    }

    public function testRefusalsAreCappedInCountAndLineLength(): void
    {
        $line = 'NOTICE Proxying refused on filtered domain "'.str_repeat('a', 400).'"';

        $refusals = new EgressProxy('p')->refusalsIn(implode("\n", array_fill(0, 150, $line)));

        self::assertCount(100, $refusals);
        self::assertSame(300, strlen($refusals[0]));
    }

    public function testTheProxyIsToldTheAllowlistAndHasNoCapabilitiesOrWritableRoot(): void
    {
        $body = new EgressProxy('job-proxy:1')->containerBody(JobSpecBuilder::in('/work/out')->build());

        self::assertSame(['INTAKT_PROXY_ALLOW=api.vendor.example,registry.example,packages.example'], $body['Env']);
        self::assertSame(['ALL'], $body['HostConfig']['CapDrop']);
        self::assertTrue($body['HostConfig']['ReadonlyRootfs']);
        self::assertSame('job-proxy:1', $body['Image']);
    }
}
