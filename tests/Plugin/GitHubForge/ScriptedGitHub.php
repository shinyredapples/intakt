<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubAppSetting;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubChangeOpener;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubForge;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\PostedComments;
use Intakt\Plugin\GitHubForge\Infrastructure\SecretScan;
use Intakt\Plugin\GitHubForge\Infrastructure\SteeringIdentities;
use Intakt\Plugin\GitHubForge\Infrastructure\TrustAnswers;
use Intakt\Plugin\GitHubForge\Infrastructure\VerdictLocks;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\Exception\TransportException;
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

    public bool $unreachable = false;

    // Answers the pull request look-up with a 429, because GitHub's pause must reach the opener's caller as a failed write.
    public bool $pullsRateLimited = false;

    // A raw body for the pull request look-up, because an answer that is no list must fail the look-up.
    public ?string $pullsAnswer = null;

    public int $createdNumber = 42;

    // Null refuses `/app`, because a retry must fail when it cannot check the author of an open pull request against the App.
    public ?string $appSlug = 'intakt';

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

        return new GitHubForge($calls, $checked, new SecretScan(dirname(__DIR__, 3), 'gitleaks'), new PostedComments($calls, $checked, self::APP_ID), new VerdictLocks($this->locks), new GitHubAppSetting(self::APP_ID, new NullLogger()));
    }

    public function opener(string $project = 'widgets', string $repository = 'acme/widgets'): GitHubChangeOpener
    {
        [$calls, $checked, $jwt] = $this->stack();

        return new GitHubChangeOpener($calls, $checked, new SecretScan(dirname(__DIR__, 3), 'gitleaks'), new WatchedRepositories([$project => ['github' => $repository]]), new SteeringIdentities(new TrustAnswers($calls), $jwt, ''));
    }

    /**
     * @return array{GitHubCalls, CheckedInstallationTokens, AppJwt}
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

        return [$calls, $checked, $jwt];
    }

    /**
     * @param array<mixed> $options
     */
    private function answer(string $method, string $url, array $options): ResponseInterface
    {
        if ($this->unreachable) {
            throw new TransportException('Connection refused for '.$url);
        }

        return 'GET' === $method ? $this->read($url) : $this->write($method, $url, $options);
    }

    private function read(string $url): ResponseInterface
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ('/app' === $path) {
            return null === $this->appSlug ? new MockResponse('{"message":"Refused"}', ['http_code' => 401]) : new MockResponse(json_encode(['slug' => $this->appSlug], JSON_THROW_ON_ERROR), ['http_code' => 200]);
        }

        if (str_ends_with($path, '/pulls')) {
            return $this->pulls($url);
        }

        return new MockResponse(json_encode(str_contains($url, 'page=1') ? $this->comments : [], JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    private function pulls(string $url): ResponseInterface
    {
        return match (true) {
            $this->pullsRateLimited => new MockResponse('{"message":"Too many requests"}', ['http_code' => 429, 'response_headers' => ['retry-after' => '60']]),
            null !== $this->pullsAnswer => new MockResponse($this->pullsAnswer, ['http_code' => 200]),
            default => new MockResponse(json_encode($this->pageOfPulls($url), JSON_THROW_ON_ERROR), ['http_code' => 200]),
        };
    }

    // Paged as GitHub pages, because a look-up that takes a full first page as the whole listing misses a pull request listed on a later one.
    /**
     * @return list<array<mixed>>
     */
    private function pageOfPulls(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $size = max(1, (int) ($query['per_page'] ?? 30));
        $page = max(1, (int) ($query['page'] ?? 1));

        return array_slice($this->openPulls, ($page - 1) * $size, $size);
    }

    /**
     * @param array<mixed> $options
     */
    private function write(string $method, string $url, array $options): ResponseInterface
    {
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

        return $this->created($method, $url);
    }

    private function created(string $method, string $url): ResponseInterface
    {
        return match (true) {
            str_ends_with($url, '/pulls') => new MockResponse(json_encode(['number' => $this->createdNumber], JSON_THROW_ON_ERROR), ['http_code' => 201]),
            'PATCH' === $method => new MockResponse(json_encode(['number' => (int) basename($url)], JSON_THROW_ON_ERROR), ['http_code' => 200]),
            default => new MockResponse('{}', ['http_code' => 201]),
        };
    }
}
