<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Outage\Infrastructure;

use Intakt\Core\Outage\Infrastructure\ConfiguredProbes;
use Intakt\Core\Outage\Infrastructure\DnsProbe;
use Intakt\Core\Outage\Infrastructure\HttpsProbe;
use Intakt\Core\Outage\Infrastructure\ProbedConnectivity;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\AnthropicDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\HuggingFaceDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\OpenAiDescriptor;
use Intakt\Plugin\SymfonyAiVendor\Infrastructure\SymfonyAiProviderCatalog;
use PHPUnit\Framework\Attributes\CoversClass;
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

        self::assertEquals(new DnsProbe('forge.example'), $probes[0]);
    }

    public function testTheHttpsProbesReachTheForgeAndEachVendorWithAnEnabledAccount(): void
    {
        $urls = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$urls): ResponseInterface {
            $urls[] = $url;

            return new MockResponse('', ['error' => 'Could not resolve host']);
        });

        foreach ($this->configured(['anthropic' => ['main'], 'openai' => [], 'claude_code' => ['main']], $http)->probes() as $probe) {
            if ($probe instanceof HttpsProbe) {
                $probe->answers();
            }
        }

        self::assertSame(['https://forge.example/', 'https://api.anthropic.com/'], $urls);
    }

    /**
     * @param array<string, list<string>> $accounts
     */
    private function configured(array $accounts, MockHttpClient $http): ConfiguredProbes
    {
        return new ConfiguredProbes($http, new SymfonyAiProviderCatalog(), $accounts, 'https://forge.example', 3.0);
    }
}
