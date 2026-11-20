<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Outage\Infrastructure;

use Intakt\Core\Outage\Infrastructure\ConfiguredProbes;
use Intakt\Core\Outage\Infrastructure\DnsProbe;
use Intakt\Core\Outage\Contract\Reachability;
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
            ['https://forge.example/', 'https://llm.example/v1', 'https://team-llm.example/v1'],
            $this->probedUrls(['openai_compatible' => ['default', 'team']], ['https://llm.example/v1', 'https://team-llm.example/v1']),
        );
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
            ['https://forge.example/', 'https://llm.example/v1'],
            $this->probedUrls(['openai_compatible' => ['default', 'team']], ['http://[bad', 'https://llm.example/v1']),
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
        yield 'a space in the path' => ['https://llm.example/my path'];
        yield 'a non-ASCII host' => ["https://m\u{00FC}nchen.example/v1"];
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
