<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class RecordedInstallation
{
    /** @var list<string> */
    public array $requested = [];

    public function __construct(
        private readonly string $token = 'minted-value',
    ) {
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient(fn (string $method, string $url): ResponseInterface => $this->answer($url));
    }

    private function answer(string $url): ResponseInterface
    {
        $this->requested[] = $url;
        if (str_ends_with($url, '/access_tokens')) {
            return new MockResponse(json_encode(['token' => $this->token, 'expires_at' => RecordedTokenExpiry::anHourFromNow()], JSON_THROW_ON_ERROR), ['http_code' => 201]);
        }

        $fixture = str_contains($url, '/installation/repositories') ? 'installation_repositories.json' : 'installation.json';

        return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture));
    }
}
