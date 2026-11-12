<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubChangeOpener;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubForge;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\PostedComments;
use Intakt\Plugin\GitHubForge\Infrastructure\SecretScan;
use Intakt\Plugin\GitHubForge\Infrastructure\VerdictLocks;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\HttpClient\ResponseInterface;

// Keeps each write with its decoded body and answers 201 unless a test refuses its path once, because a test must see every write the forge sent and in which order; lists the comments posted so far, so a retry can find its own.
final class ScriptedGitHub
{
    private const string APP_ID = '12345';

    /** @var list<array{string, array<mixed>}> */
    public array $writes = [];

    /** @var list<array<mixed>> */
    public array $openPulls = [];

    /** @var list<array<mixed>> */
    private array $comments = [];

    /** @var array<string, int> */
    private array $refusals = [];

    // Shared by every forge it builds, so a retry meets the lock an earlier delivery took, as the workers share one lock store.
    public function __construct(
        private readonly LockFactory $locks = new LockFactory(new InMemoryStore()),
    ) {
    }

    public function refuseOnce(string $pathEnding, int $status): void
    {
        $this->refusals[$pathEnding] = $status;
    }

    public function forge(): GitHubForge
    {
        [$calls, $checked] = $this->stack();

        return new GitHubForge($calls, $checked, new SecretScan(dirname(__DIR__, 3), 'gitleaks'), new PostedComments($calls, $checked, self::APP_ID), new VerdictLocks($this->locks));
    }

    public function opener(): GitHubChangeOpener
    {
        [$calls, $checked] = $this->stack();

        return new GitHubChangeOpener($calls, $checked, new SecretScan(dirname(__DIR__, 3), 'gitleaks'), new WatchedRepositories(['widgets' => ['github' => 'acme/widgets']]));
    }

    /**
     * @return array{GitHubCalls, CheckedInstallationTokens}
     */
    private function stack(): array
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $identity = new RecordedInstallation('installation-secret')->client();
        $jwt = new AppJwt(self::APP_ID, AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $identity, new NullLogger()), '678', $clock);
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $readings = FreshReadings::at($clock);
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($identity, $readings, $tokens)), $expected, new NullLogger());
        $http = new MockHttpClient($this->answer(...), 'https://api.github.com');
        $calls = new GitHubCalls($http, $readings, $tokens);
        $checked = new CheckedInstallationTokens($readBack, $tokens);

        return [$calls, $checked];
    }

    /**
     * @param array<mixed> $options
     */
    private function answer(string $method, string $url, array $options): ResponseInterface
    {
        if ('GET' === $method && str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', '/pulls')) {
            return new MockResponse(json_encode($this->openPulls, JSON_THROW_ON_ERROR), ['http_code' => 200]);
        }

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

        // Stamped with the App's id, because GitHub stamps a comment posted with an installation token so, and a retry counts only such a comment as its own.
        if (str_ends_with($url, '/comments')) {
            $this->comments[] = [...$payload, 'performed_via_github_app' => ['id' => (int) self::APP_ID]];
        }

        if (str_ends_with($url, '/pulls')) {
            return new MockResponse(json_encode(['number' => 42], JSON_THROW_ON_ERROR), ['http_code' => 201]);
        }

        if ('PATCH' === $method) {
            return new MockResponse(json_encode(['number' => (int) basename($url)], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        }

        return new MockResponse('{}', ['http_code' => 201]);
    }
}
