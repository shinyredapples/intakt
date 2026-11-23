<?php

declare(strict_types=1);

namespace Intakt\Core\Outage\Infrastructure;

use Intakt\Core\Ai\Contract\AiServices;
use Intakt\Core\Outage\Contract\OutageServices;
use Intakt\Plugin\GitHubForge\Contract\GitHubForgeServices;
use Intakt\Plugin\Vendor\Contract\ProviderCatalog;
use Intakt\Plugin\Vendor\Contract\ProviderDescriptor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Uri\Rfc3986\Uri;
use Uri\WhatWg\Url;

// The hosts come from the forge's and the vendors' own configuration, so the check never needs a setting of its own.
final readonly class ConfiguredProbes
{
    /**
     * @param array<string, list<string>> $accounts
     * @param list<string>                $compatibleBaseUrls
     */
    public function __construct(
        private HttpClientInterface $http,
        private ProviderCatalog $catalog,
        #[Autowire(param: AiServices::ACCOUNTS)]
        private array $accounts,
        #[Autowire(param: AiServices::COMPATIBLE_BASE_URLS)]
        private array $compatibleBaseUrls,
        #[Autowire(param: GitHubForgeServices::API_URL)]
        private string $forgeUrl,
        #[Autowire(param: OutageServices::PROBE_TIMEOUT)]
        private float $timeout,
    ) {
    }

    public function connectivity(): ProbedConnectivity
    {
        return new ProbedConnectivity($this->probes());
    }

    /**
     * @return list<Probe>
     */
    public function probes(): array
    {
        $forge = new Uri($this->forgeUrl);

        return [
            new DnsProbe((string) $forge->getHost(), $this->timeout),
            new HttpsProbe($this->http, $forge, $this->timeout),
            ...array_map(fn (ProviderDescriptor $vendor): Probe => new HttpsProbe($this->http, $vendor->baseUrl(), $this->timeout), $this->vendorsInUse()),
            ...array_map(fn (Uri $url): Probe => new HttpsProbe($this->http, $url, $this->timeout), $this->compatibleUrls()),
        ];
    }

    /**
     * @return list<ProviderDescriptor>
     */
    private function vendorsInUse(): array
    {
        // A descriptor's id matches an account key only for a hosted vendor; an OpenAI-compatible account names no descriptor, so its own base URL is probed instead.
        return array_values(array_filter(
            $this->catalog->descriptors(),
            fn (ProviderDescriptor $vendor): bool => [] !== ($this->accounts[$vendor->id()] ?? []),
        ));
    }

    /**
     * @return list<Uri>
     */
    private function compatibleUrls(): array
    {
        // A base URL is free text from the environment: an unparseable one would make the check throw, and a plain HTTP answer (a local proxy, say) is not the network.
        return array_values(array_filter(
            array_map(static fn (string $text): ?Uri => Uri::parse($text) ?? self::lenientOrigin($text), $this->compatibleBaseUrls),
            static fn (?Uri $url): bool => 'https' === $url?->getScheme() && '' !== (string) $url->getHost(),
        ));
    }

    // The provider's HTTP client requests a base URL the strict parser rejects (an internationalized host, a space in the path), so its endpoint is in use and its origin is probed.
    private static function lenientOrigin(string $text): ?Uri
    {
        // The lenient parser repairs what the HTTP client refuses: surrounding whitespace, a tab or line break, backslashes, and a missing authority (it reads `https:///v1` as host `v1`).
        if ($text !== trim($text, "\x00..\x20") || false !== strpbrk($text, "\\\r\n\t") || 1 !== preg_match('#^https://[^/?\#]#i', $text)) {
            return null;
        }

        $url = Url::parse($text);
        $host = $url?->getAsciiHost();

        if (!$url instanceof Url || null === $host || '' === $host) {
            return null;
        }

        return Uri::parse('https://'.$host.(null === $url->getPort() ? '' : ':'.$url->getPort()));
    }
}
