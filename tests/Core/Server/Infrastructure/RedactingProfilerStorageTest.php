<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Server\Infrastructure;

use Intakt\Core\Server\Infrastructure\RedactingProfilerStorage;
use Intakt\Tests\Core\Review\UseCase\RunReviewRound\RoundFixture;
use Intakt\Tests\Support\WebTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LogLevel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpClient\DataCollector\HttpClientDataCollector;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\TraceableHttpClient;
use Symfony\Component\HttpKernel\DataCollector\RequestDataCollector;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\HttpKernel\Profiler\Profiler;

// A saved profile is a file on disk that outlives the request, so no credential may reach it (#2721).
#[CoversClass(RedactingProfilerStorage::class)]
final class RedactingProfilerStorageTest extends WebTestCase
{
    private const string CANARY = 'synthetic-secret-2721';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient(server: ['HTTP_HOST' => 'localhost:7780']);
        $this->browser->disableReboot();
        $this->browser->enableProfiler();
        new RoundFixture(self::getContainer());
    }

    public function testASavedProfileHoldsNoValueOfAnEnvironmentVariable(): void
    {
        $this->browser->request('GET', '/reviews', server: ['APP_SECRET' => self::CANARY]);

        self::assertStringNotContainsString(self::CANARY, serialize($this->savedProfile()));
        self::assertSame(str_repeat('*', strlen(self::CANARY)), $this->requestCollector()->getRequestServer(raw: true)->get('APP_SECRET'));
    }

    public function testASavedProfileHoldsNoEnvironmentVariableNamedLikeRequestMetadata(): void
    {
        $this->browser->request('GET', '/reviews', server: ['SERVER_PROVIDER_API_KEY' => self::CANARY, 'REQUEST_TOKEN' => self::CANARY.'-request']);

        self::assertStringNotContainsString(self::CANARY, serialize($this->savedProfile()));
    }

    public function testTheSavedTraceOfAFailedRequestHoldsNoValueOfAnEnvironmentVariable(): void
    {
        $ignoredArguments = ini_set('zend.exception_ignore_args', '0');

        try {
            $this->browser->request('POST', '/setup/first-review', server: ['APP_SECRET' => self::CANARY]);
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ignoredArguments);
        }

        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The form token is missing or wrong, so nothing was started."', BadRequestHttpException::class));
        $saved = serialize($this->savedProfile());
        self::assertStringNotContainsString(self::CANARY, $saved);
        self::assertStringNotContainsString(substr(self::CANARY, 0, 15), $saved);
    }

    public function testASavedProfileHoldsNoBearerToken(): void
    {
        $this->browser->request('GET', '/reviews', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::CANARY]);

        self::assertStringNotContainsString(self::CANARY, serialize($this->savedProfile()));
    }

    public function testTheSavedProfileOfARejectedMcpCallHoldsNoBearerToken(): void
    {
        $this->browser->request('POST', '/mcp/owner', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::CANARY, 'CONTENT_TYPE' => 'application/json'], content: '{"jsonrpc":"2.0","id":1,"method":"tools/list"}');

        self::assertResponseStatusCodeSame(401);
        self::assertStringNotContainsString(self::CANARY, serialize($this->savedProfile()));
    }

    public function testASavedProfileHoldsNoCookieTheRequestSent(): void
    {
        $this->browser->getCookieJar()->set(new Cookie('MOCKSESSID', self::CANARY));

        $this->browser->request('GET', '/reviews');

        self::assertStringNotContainsString(self::CANARY, serialize($this->savedProfile()));
    }

    public function testASavedProfileHoldsNoCookieTheResponseSet(): void
    {
        $this->browser->request('GET', '/setup');

        $cookies = $this->browser->getResponse()->headers->getCookies();
        self::assertNotSame([], $cookies);
        $saved = serialize($this->savedProfile());

        foreach ($cookies as $cookie) {
            self::assertStringNotContainsString((string) $cookie->getValue(), $saved);
        }
    }

    public function testTheSavedProfileOfAPostedProviderKeyHoldsNoKey(): void
    {
        $this->browser->request('POST', '/setup/provider-key', ['key' => self::CANARY]);

        self::assertLoggedError(LogLevel::WARNING, sprintf('Uncaught PHP Exception %s: "The form token is missing or wrong, so no key was kept."', BadRequestHttpException::class));
        self::assertStringNotContainsString(self::CANARY, serialize($this->savedProfile()));
    }

    public function testASavedProfileHoldsNoCredentialHeaderOfAnOutgoingCall(): void
    {
        $saved = $this->savedProfileOfACall(['headers' => ['x-api-key' => self::CANARY, 'anthropic-version' => '2023-06-01']]);

        self::assertStringNotContainsString(self::CANARY, $saved);
        self::assertStringContainsString('2023-06-01', $saved);
    }

    public function testASavedProfileHoldsNoBearerTokenOfAnOutgoingCall(): void
    {
        self::assertStringNotContainsString(self::CANARY, $this->savedProfileOfACall(['auth_bearer' => self::CANARY]));
    }

    public function testASavedProfileHoldsNoPasswordOfAnOutgoingCall(): void
    {
        $saved = $this->savedProfileOfACall(['auth_basic' => ['intakt', self::CANARY]]);

        self::assertStringNotContainsString(self::CANARY, $saved);
        self::assertStringNotContainsString(base64_encode('intakt:'.self::CANARY), $saved);
    }

    public function testASavedProfileStillShowsWhatTheRequestAsked(): void
    {
        $this->browser->request('GET', '/reviews?page=2');

        $server = $this->requestCollector()->getRequestServer(raw: true);
        self::assertSame('/reviews?page=2', $server->get('REQUEST_URI'));
        self::assertSame('localhost:7780', $server->get('HTTP_HOST'));
        self::assertSame('GET', $server->get('REQUEST_METHOD'));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function savedProfileOfACall(array $options): string
    {
        $client = new TraceableHttpClient(new MockHttpClient());
        $collector = new HttpClientDataCollector();
        $collector->registerClient('provider', $client);
        $client->request('POST', 'http://localhost/v1/messages', $options)->getContent();
        $profile = new Profile(bin2hex(random_bytes(3)));
        $profile->setMethod('GET');
        $profile->setUrl('http://localhost/reviews');
        $profile->setStatusCode(200);
        $profile->addCollector($collector);

        $this->profiler()->saveProfile($profile);

        return serialize($this->profiler()->loadProfile($profile->getToken()));
    }

    private function savedProfile(): Profile
    {
        $token = $this->browser->getResponse()->headers->get('X-Debug-Token');
        self::assertIsString($token);
        $profile = $this->profiler()->loadProfile($token);
        self::assertInstanceOf(Profile::class, $profile);

        return $profile;
    }

    private function requestCollector(): RequestDataCollector
    {
        $collector = $this->savedProfile()->getCollector('request');
        self::assertInstanceOf(RequestDataCollector::class, $collector);

        return $collector;
    }

    private function profiler(): Profiler
    {
        $profiler = self::getContainer()->get('profiler');
        self::assertInstanceOf(Profiler::class, $profiler);

        return $profiler;
    }
}
