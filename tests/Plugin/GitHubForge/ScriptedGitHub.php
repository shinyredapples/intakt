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
use Intakt\Plugin\GitHubForge\Infrastructure\SecretScan;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

// GitHub's write API as a script: every write is kept with its decoded JSON body and answered 201, or with the status a test names for a path ending.
final class ScriptedGitHub
{
    /** @var list<array{string, array<mixed>}> */
    public array $writes = [];

    /** @var array<string, int> */
    private array $refusals = [];

    public function refuse(string $pathEnding, int $status): void
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

        return new GitHubForge(new GitHubCalls($http, $readings, $tokens), new CheckedInstallationTokens($readBack, $tokens), new SecretScan(dirname(__DIR__, 3), 'gitleaks'));
    }

    /**
     * @param array<mixed> $options
     */
    private function answer(string $method, string $url, array $options): ResponseInterface
    {
        $body = $options['body'] ?? '';
        $this->writes[] = [$method.' '.$url, (array) json_decode(is_string($body) ? $body : '', true, flags: JSON_THROW_ON_ERROR)];

        foreach ($this->refusals as $ending => $status) {
            if (str_ends_with($url, $ending)) {
                return new MockResponse('{"message":"Refused"}', ['http_code' => $status]);
            }
        }

        return new MockResponse('{}', ['http_code' => 201]);
    }
}
