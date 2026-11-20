<?php

declare(strict_types=1);

namespace Intakt\Core\Outage\Infrastructure;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Uri\Rfc3986\Uri;

// A bare HEAD with no credential and no body: any status means the host answered, so a 401 or a 500 counts as reachable.
final readonly class HttpsProbe implements Probe
{
    public function __construct(
        private HttpClientInterface $http,
        private Uri $url,
        private float $timeout,
    ) {
    }

    public function answers(): bool
    {
        try {
            $this->http->request('HEAD', $this->url->toString(), [
                'timeout' => $this->timeout,
                'max_duration' => $this->timeout,
                'max_redirects' => 0,
            ])->getStatusCode();
        } catch (TransportExceptionInterface) {
            return false;
        }

        return true;
    }
}
