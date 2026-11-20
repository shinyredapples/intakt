<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server\Infrastructure;

use Intakt\Core\Server\Infrastructure\RedactingProfiler;
use Intakt\Core\Server\Infrastructure\RedactRequestProfilesPass;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpKernel\DataCollector\RequestDataCollector;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\Profiler;

// A saved profile is a file on disk that outlives the request, so the environment's secrets must not reach it (#2721).
#[CoversClass(RedactingProfiler::class)]
#[CoversClass(RedactRequestProfilesPass::class)]
final class RedactingProfilerTest extends WebTestCase
{
    private const string CANARY = 'synthetic-secret-2721';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        $this->browser->disableReboot();
        $this->browser->enableProfiler();
    }

    public function testASavedProfileHoldsNoValueOfAnEnvironmentVariable(): void
    {
        $this->browser->request('GET', '/reviews', server: ['APP_SECRET' => self::CANARY]);

        self::assertStringNotContainsString(self::CANARY, serialize($this->savedProfile()));
        self::assertSame(RedactingProfiler::MASK, $this->requestCollector()->getRequestServer(raw: true)->get('APP_SECRET'));
    }

    public function testASavedProfileHoldsNoBearerToken(): void
    {
        $this->browser->request('GET', '/reviews', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::CANARY]);

        self::assertStringNotContainsString(self::CANARY, serialize($this->savedProfile()));
    }

    public function testASavedProfileStillShowsWhatTheRequestAsked(): void
    {
        $this->browser->request('GET', '/reviews?page=2');

        $server = $this->requestCollector()->getRequestServer(raw: true);
        self::assertSame('/reviews?page=2', $server->get('REQUEST_URI'));
        self::assertSame('localhost:7780', $server->get('HTTP_HOST'));
    }

    private function savedProfile(): Profile
    {
        $token = $this->browser->getResponse()->headers->get('X-Debug-Token');
        self::assertIsString($token);
        $profiler = self::getContainer()->get('profiler');
        self::assertInstanceOf(Profiler::class, $profiler);
        $profile = $profiler->loadProfile($token);
        self::assertInstanceOf(Profile::class, $profile);

        return $profile;
    }

    private function requestCollector(): RequestDataCollector
    {
        $collector = $this->savedProfile()->getCollector('request');
        self::assertInstanceOf(RequestDataCollector::class, $collector);

        return $collector;
    }
}
