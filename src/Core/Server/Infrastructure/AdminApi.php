<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Intakt\Core\Server\Contract\AdminApiNotOnLocalhost;
use Intakt\Core\Server\Contract\EndpointDown;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Uri\InvalidUriException;
use Uri\Rfc3986\Uri;

final readonly class AdminApi
{
    private const array LOOPBACK_HOSTS = ['localhost', '127.0.0.1', '[::1]'];
    // No redirects, so an answer can never send the reader off localhost; short limits, so a hung server cannot hold a page.
    private const array OPTIONS = ['max_redirects' => 0, 'timeout' => 2, 'max_duration' => 2];

    public function __construct(
        private HttpClientInterface $http,
        private string $baseUrl,
    ) {
    }

    /**
     * @return array{status: int, body: string}|EndpointDown
     *
     * @throws AdminApiNotOnLocalhost
     */
    public function get(string $path): array|EndpointDown
    {
        $url = $this->onLocalhost()->withPath($path)->toRawString();
        try {
            $response = $this->http->request('GET', $url, self::OPTIONS);

            return ['status' => $response->getStatusCode(), 'body' => $response->getContent(false)];
        } catch (ExceptionInterface) {
            // Without the transport's message, because it quotes the whole URL and the reason is shown on the board.
            return new EndpointDown($path.' did not answer');
        }
    }

    private function onLocalhost(): Uri
    {
        try {
            $base = new Uri($this->baseUrl);
        } catch (InvalidUriException) {
            throw AdminApiNotOnLocalhost::becauseItIsNotAUrl();
        }
        $scheme = $base->getScheme() ?? '';
        $host = $base->getHost() ?? '';
        if ('http' !== $scheme || !in_array($host, self::LOOPBACK_HOSTS, true) || null !== $base->getUserInfo()) {
            throw AdminApiNotOnLocalhost::becauseOfTheAddress($scheme, $host);
        }

        return $base;
    }
}
