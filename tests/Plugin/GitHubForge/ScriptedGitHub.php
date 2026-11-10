<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubForge;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\PostedComments;
use Intakt\Plugin\GitHubForge\Infrastructure\SecretScan;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

// Keeps each write with its decoded body and answers 201 unless a test refuses its path once, because a test must see every write the forge sent and in which order; lists the comments posted so far, so a retry can find its own.
final class ScriptedGitHub
{
    /** @var list<array{string, array<mixed>}> */
    public array $writes = [];

    /** @var list<array<mixed>> */
    private array $comments = [];

    /** @var array<string, int> */
    private array $refusals = [];

    public function refuseOnce(string $pathEnding, int $status): void
    {
        $this->refusals[$pathEnding] = $status;
    }

    public function forge(): GitHubForge
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $identity = new RecordedInstallation('installation-secret')->client();
        $jwt = new AppJwt('12345', AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $identity, new NullLogger()), '678', $clock);
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $readings = FreshReadings::at($clock);
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($identity, $readings, $tokens)), $expected, new NullLogger());
        $http = new MockHttpClient($this->answer(...), 'https://api.github.com');
        $calls = new GitHubCalls($http, $readings, $tokens);
        $checked = new CheckedInstallationTokens($readBack, $tokens);

        return new GitHubForge($calls, $checked, new SecretScan(dirname(__DIR__, 3), 'gitleaks'), new PostedComments($calls, $checked));
    }

    /**
     * @param array<mixed> $options
     */
    private function answer(string $method, string $url, array $options): ResponseInterface
    {
        if ('GET' === $method) {
            return new MockResponse(json_encode(str_contains($url, 'page=1') ? $this->comments : [], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        }

        $body = $options['body'] ?? '';
        $payload = (array) json_decode(is_string($body) ? $body : '', true, flags: JSON_THROW_ON_ERROR);
        $this->writes[] = [$method.' '.$url, $payload];

        foreach ($this->refusals as $ending => $status) {
            if (str_ends_with($url, $ending)) {
                unset($this->refusals[$ending]);

                return new MockResponse('{"message":"Refused"}', ['http_code' => $status]);
            }
        }

        if (str_ends_with($url, '/comments')) {
            $this->comments[] = $payload;
        }

        return new MockResponse('{}', ['http_code' => 201]);
    }
}
