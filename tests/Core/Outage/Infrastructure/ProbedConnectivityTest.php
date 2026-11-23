<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Outage\Infrastructure;

use Intakt\Core\Outage\Contract\Reachability;
use Intakt\Core\Outage\Infrastructure\HttpsProbe;
use Intakt\Core\Outage\Infrastructure\Probe;
use Intakt\Core\Outage\Infrastructure\ProbedConnectivity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Uri\Rfc3986\Uri;

#[CoversClass(ProbedConnectivity::class)]
#[UsesClass(HttpsProbe::class)]
final class ProbedConnectivityTest extends TestCase
{
    public function testOneAnsweringProbeAmongFailingOnesMakesTheCheckOnline(): void
    {
        $connectivity = new ProbedConnectivity([$this->failing(), $this->failing(), $this->answering()]);

        self::assertSame(Reachability::Online, $connectivity->reachability());
    }

    public function testAHostAnsweringWithHttp500MakesTheCheckOnline(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 500]));
        $connectivity = new ProbedConnectivity([$this->failing(), new HttpsProbe($http, new Uri('https://forge.example'), 3.0)]);

        self::assertSame(Reachability::Online, $connectivity->reachability());
    }

    public function testEveryProbeFailingMakesTheCheckOffline(): void
    {
        $http = new MockHttpClient(new MockResponse('', ['error' => 'Could not resolve host']));
        $connectivity = new ProbedConnectivity([$this->failing(), new HttpsProbe($http, new Uri('https://forge.example'), 3.0), $this->failing()]);

        self::assertSame(Reachability::Offline, $connectivity->reachability());
    }

    public function testNoProbeRunsAfterTheFirstThatAnswers(): void
    {
        $unreached = $this->createMock(Probe::class);
        $unreached->expects($this->never())->method('answers');

        new ProbedConnectivity([$this->answering(), $unreached])->reachability();
    }

    private function failing(): Probe
    {
        $probe = self::createStub(Probe::class);
        $probe->method('answers')->willReturn(false);

        return $probe;
    }

    private function answering(): Probe
    {
        $probe = self::createStub(Probe::class);
        $probe->method('answers')->willReturn(true);

        return $probe;
    }
}
