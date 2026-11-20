<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server\Infrastructure;

use Intakt\Core\Server\Infrastructure\ProfilerCollectorAllowListPass;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\Profiler;

// A saved profile is a file on disk that outlives the request, so it holds only what measures the request and no credential (#2721).
#[CoversClass(ProfilerCollectorAllowListPass::class)]
final class SavedProfileTest extends WebTestCase
{
    private const string CANARY = 'synthetic-secret-2721';

    private KernelBrowser $browser;

    /** @var list<string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        $this->browser->disableReboot();
    }

    public function testASavedProfileHoldsExactlyTheCollectorsThatMeasureARequest(): void
    {
        $this->browser->enableProfiler();

        $this->browser->request('GET', '/reviews');

        $profile = $this->browser->getProfile();
        self::assertInstanceOf(Profile::class, $profile);
        self::assertEqualsCanonicalizing(ProfilerCollectorAllowListPass::COLLECTORS, array_keys($profile->getCollectors()));
    }

    public function testNoSavedProfileHoldsACanaryCredential(): void
    {
        $this->browser->getCookieJar()->set(new Cookie('MOCKSESSID', self::CANARY.'-cookie'));
        $this->browser->enableProfiler();
        $this->browser->request('GET', '/reviews', server: ['INTAKT_CANARY' => self::CANARY.'-env', 'SERVER_PROVIDER_API_KEY' => self::CANARY.'-server']);
        $this->keepProfileToken();
        $this->browser->enableProfiler();
        $this->browser->request(
            'POST',
            '/mcp/review',
            server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::CANARY.'-bearer', 'CONTENT_TYPE' => 'application/json'],
            content: '{"jsonrpc":"2.0","id":1,"method":"tools/list"}',
        );
        self::assertResponseStatusCodeSame(401);
        $this->keepProfileToken();
        $this->browser->enableProfiler();
        $this->browser->request('POST', '/setup/provider-key', ['key' => self::CANARY.'-posted']);
        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The form token is missing or wrong, so no key was kept."', BadRequestHttpException::class));
        $this->keepProfileToken();

        foreach ($this->tokens as $token) {
            self::assertStringNotContainsString(self::CANARY, $this->savedProfile($token));
        }
    }

    private function keepProfileToken(): void
    {
        $token = $this->browser->getResponse()->headers->get('X-Debug-Token');
        self::assertIsString($token);
        $this->tokens[] = $token;
    }

    // Read back from the profiler's storage, so the check sees what was written to disk, not what the request held in memory.
    private function savedProfile(string $token): string
    {
        $profiler = self::getContainer()->get('profiler');
        self::assertInstanceOf(Profiler::class, $profiler);
        $profile = $profiler->loadProfile($token);
        self::assertInstanceOf(Profile::class, $profile);

        return serialize($profile);
    }
}
