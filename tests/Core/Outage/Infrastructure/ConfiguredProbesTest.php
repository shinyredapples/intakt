<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Outage\Infrastructure;

use Intakt\Core\Outage\Contract\Reachability;
use Intakt\Core\Outage\Infrastructure\ConfiguredProbes;
use Intakt\Core\Outage\Infrastructure\DnsProbe;
use Intakt\Core\Outage\Infrastructure\HttpsProbe;
use Intakt\Core\Outage\Infrastructure\Probe;
use Intakt\Core\Outage\Infrastructure\ProbedConnectivity;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AnthropicDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\HuggingFaceDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\OpenAiDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiProviderCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

#[CoversClass(ConfiguredProbes::class)]
#[UsesClass(DnsProbe::class)]
#[UsesClass(HttpsProbe::class)]
#[UsesClass(ProbedConnectivity::class)]
#[UsesClass(SymfonyAiProviderCatalog::class)]
#[UsesClass(AnthropicDescriptor::class)]
#[UsesClass(OpenAiDescriptor::class)]
#[UsesClass(HuggingFaceDescriptor::class)]
final class ConfiguredProbesTest extends TestCase
{
    public function testTheForgeHostIsLookedUpFirst(): void
    {
        $probes = $this->configured(['anthropic' => ['main']], new MockHttpClient())->probes();

        self::assertEquals(new DnsProbe('forge.example', 3.0), $probes[0]);
    }

    public function testTheHttpsProbesReachTheForgeAndEachVendorWithAnEnabledAccount(): void
    {
        self::assertSame(
            ['https://forge.example/', 'https://api.anthropic.com/'],
            $this->probedUrls(['anthropic' => ['main'], 'openai' => [], 'claude_code' => ['main']], []),
        );
    }

    public function testEachEnabledCompatibleAccountIsProbedAtItsOwnBaseUrl(): void
    {
        self::assertSame(
            ['https://forge.example/', 'https://llm.example/', 'https://team-llm.example/'],
            $this->probedUrls(['openai_compatible' => ['default', 'team']], ['https://llm.example/v1', 'https://team-llm.example/v1']),
        );
    }

    #[DataProvider('nonHttpsBaseUrls')]
    public function testACompatibleBaseUrlThatIsNotAbsoluteHttpsIsNotRequested(string $url): void
    {
        self::assertSame(
            ['https://forge.example/', 'https://llm.example/'],
            $this->probedUrls(['openai_compatible' => ['default', 'team']], [$url, 'https://llm.example/v1']),
        );
    }

    #[DataProvider('nonHttpsBaseUrls')]
    public function testAnAnsweringPlainHttpOrRelativeBaseUrlCannotMakeTheCheckOnline(string $url): void
    {
        $http = new MockHttpClient(static fn (string $method, string $requested): ResponseInterface => str_starts_with($requested, 'https://')
            ? new MockResponse('', ['error' => 'Could not resolve host'])
            : new MockResponse('', ['http_code' => 500]));

        self::assertSame(Reachability::Offline, $this->httpsReachability($http, $url));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonHttpsBaseUrls(): iterable
    {
        yield 'plain http' => ['http://model.example/v1'];
        yield 'a relative path' => ['/v1'];
        yield 'https with an empty host' => ['https:///v1'];
        yield 'another scheme' => ['ftp://model.example/v1'];
    }

    #[DataProvider('unparseableBaseUrls')]
    public function testAnUnparseableCompatibleBaseUrlNextToAnAnsweringForgeIsOnline(string $url): void
    {
        $http = new MockHttpClient(static fn (): ResponseInterface => new MockResponse('', ['http_code' => 500]));

        self::assertSame(Reachability::Online, $this->httpsReachability($http, $url));
    }

    #[DataProvider('unparseableBaseUrls')]
    public function testAnUnparseableCompatibleBaseUrlWithEveryOtherProbeFailingIsOffline(string $url): void
    {
        $http = new MockHttpClient(static fn (): ResponseInterface => new MockResponse('', ['error' => 'Could not resolve host']));

        self::assertSame(Reachability::Offline, $this->httpsReachability($http, $url));
    }

    public function testAnUnparseableCompatibleBaseUrlIsNotRequested(): void
    {
        self::assertSame(
            ['https://forge.example/', 'https://llm.example/'],
            $this->probedUrls(['openai_compatible' => ['default', 'team']], ['http://[bad', 'https://llm.example/v1']),
        );
    }

    // Not probed, because a zone ID names a host on the local link and reaching it says nothing about reaching the outside.
    public function testAScopedIpv6BaseUrlAddsNoProbe(): void
    {
        self::assertSame(
            ['https://forge.example/'],
            $this->probedUrls(['openai_compatible' => ['default']], ['https://[2001:db8::1%25eth0]/v1']),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unparseableBaseUrls(): iterable
    {
        yield 'an unclosed bracket' => ['http://[bad'];
        yield 'a trailing space' => ['https://llm.example/v1 '];
        yield 'a leading space' => [' https://llm.example/v1'];
        yield 'a trailing line feed' => ["https://llm.example/my path\n"];
        yield 'a tab in the path' => ["https://llm.example/my\tpath"];
        yield 'backslashes for slashes' => ['https:\\\\llm.example\\my path'];
        yield 'an empty host before a space' => ['https:///v1/my path'];
        yield 'no authority before a space' => ['https:llm.example/my path'];
        yield 'a space in the host' => ['https://llm example/v1'];
        yield 'plain http with a non-ASCII host' => ["http://m\u{00FC}nchen.example/v1"];
    }

    #[DataProvider('baseUrlsOnlyTheHttpClientAccepts')]
    public function testAnAnsweringBaseUrlTheHttpClientAcceptsIsOnlineWithEveryOtherProbeFailing(string $url, string $origin): void
    {
        $http = new MockHttpClient(static fn (string $method, string $requested): ResponseInterface => $requested === $origin
            ? new MockResponse('', ['http_code' => 500])
            : new MockResponse('', ['error' => 'Could not resolve host']));

        self::assertSame(Reachability::Online, $this->httpsReachability($http, $url));
    }

    #[DataProvider('baseUrlsOnlyTheHttpClientAccepts')]
    public function testABaseUrlTheHttpClientAcceptsIsRequestedAtItsAsciiOrigin(string $url, string $origin): void
    {
        self::assertSame(['https://forge.example/', $origin], $this->probedUrls(['openai_compatible' => ['default']], [$url]));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function baseUrlsOnlyTheHttpClientAccepts(): iterable
    {
        yield 'a non-ASCII host' => ["https://m\u{00FC}nchen.example/v1", 'https://xn--mnchen-3ya.example/'];
        yield 'a non-ASCII host with a port' => ["https://m\u{00FC}nchen.example:8443/v1", 'https://xn--mnchen-3ya.example:8443/'];
        yield 'a space in the path' => ['https://llm.example/my path', 'https://llm.example/'];
        yield 'an uppercase scheme and a space' => ['HTTPS://LLM.example/my path', 'https://llm.example/'];
        yield 'an address literal with a port and a space' => ['https://[2001:db8::1]:8443/my path', 'https://[2001:0db8:0000:0000:0000:0000:0000:0001]:8443/'];
        yield 'a credential with a space' => ['https://user:pa ss@llm.example/v1?key=a b', 'https://llm.example/'];
    }

    // Only the HTTPS probes run here: the DNS probe would look up the forge's name on the real network.
    private function httpsReachability(MockHttpClient $http, string $compatibleBaseUrl): Reachability
    {
        $probes = $this->configured(['openai_compatible' => ['default']], $http, [$compatibleBaseUrl])->probes();

        return new ProbedConnectivity(array_values(array_filter($probes, static fn (Probe $probe): bool => $probe instanceof HttpsProbe)))->reachability();
    }

    /**
     * @param array<string, list<string>> $accounts
     * @param list<string>                $compatibleBaseUrls
     *
     * @return list<string>
     */
    private function probedUrls(array $accounts, array $compatibleBaseUrls): array
    {
        $urls = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$urls): ResponseInterface {
            $urls[] = $url;

            return new MockResponse('', ['error' => 'Could not resolve host']);
        });

        foreach ($this->configured($accounts, $http, $compatibleBaseUrls)->probes() as $probe) {
            if ($probe instanceof HttpsProbe) {
                $probe->answers();
            }
        }

        return $urls;
    }

    /**
     * @param array<string, list<string>> $accounts
     * @param list<string>                $compatibleBaseUrls
     */
    private function configured(array $accounts, MockHttpClient $http, array $compatibleBaseUrls = []): ConfiguredProbes
    {
        return new ConfiguredProbes($http, new SymfonyAiProviderCatalog(), $accounts, $compatibleBaseUrls, 'https://forge.example', 3.0);
    }
}
