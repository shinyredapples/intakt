<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Contract\PartIssue;
use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\CreatedParts;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubTaskParts;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\NextLinks;
use Intakt\Plugin\GitHubForge\Infrastructure\PartIssueCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use Intakt\Tests\Plugin\SymfonyAiVendor\InMemoryLedger;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Contracts\HttpClient\ResponseInterface;

// The issues of acme/widget on GitHub: lists them a page at a time in the order they were created and keeps each create as an issue of the App, so a test sees what the owner would see.
final class ScriptedIssueTracker
{
    public const string APP_ID = '12345';
    public const string PROJECT = 'widget';
    private const string MOMENT = '2026-11-28T12:00:00Z';

    /** @var list<array<mixed>> */
    public array $issues = [];

    /** @var list<string> */
    public array $requests = [];

    public ?int $refusal = null;

    // Shorter than per_page asked for in a test of the Link header, because GitHub may serve a short page and still have more.
    public int $served = 100;

    // Answered after the issue is kept, because GitHub can create an issue and still answer 502.
    public ?int $answerToAStoredCreate = null;

    // Answered instead of keeping the issue, because GitHub stores nothing for a create it refuses or cannot serve.
    public ?int $answerToAnUnstoredCreate = null;

    // Sent with that answer, so a test can make it a rate limit.
    /** @var list<string> */
    public array $headersOfAnUnstoredCreate = [];

    // What GitHub stores other than what was sent, for a create GitHub does not keep as sent.
    /** @var array<string, mixed> */
    public array $storedOtherwise = [];

    // Kept across runs, because a later run reads what an earlier run's create kept there.
    public InMemoryLedger $ledger;

    private readonly LockFactory $locks;

    private ?LockInterface $held = null;

    public function __construct()
    {
        $this->locks = new LockFactory(new InMemoryStore());
        $this->ledger = new InMemoryLedger();
    }

    public function byPerson(string $body, bool $pullRequest = false): void
    {
        $issue = ['number' => count($this->issues) + 1, 'title' => 'By hand', 'body' => $body, 'user' => ['login' => 'stranger', 'type' => 'User'], 'performed_via_github_app' => null];
        $this->issues[] = $pullRequest ? [...$issue, 'pull_request' => ['url' => 'https://api.github.com/x']] : $issue;
    }

    /**
     * @return list<array<mixed>>
     */
    public function filed(): array
    {
        return array_values(array_filter($this->issues, static fn (array $issue): bool => null !== $issue['performed_via_github_app']));
    }

    // Held by another run, so a test sees what a busy part does.
    public function holdTheLock(PartIssue $part): void
    {
        $subject = 'part-issue-'.hash('sha256', strlen(self::PROJECT).':'.self::PROJECT.$part->marker);
        $this->held = $this->locks->createLock($subject);
        $this->held->acquire();
    }

    public function parts(string $repository = 'acme/widget'): GitHubTaskParts
    {
        return new GitHubTaskParts($this->calls(), new WatchedRepositories([self::PROJECT => ['github' => $repository]]), new CreatedParts($this->ledger, $this->locks), self::APP_ID);
    }

    // With the permissions the configuration expects, so a test can make the installation differ from it and no token be handed out.
    public function calls(string $permissions = 'contents:write,pull_requests:write,workflows:write'): PartIssueCalls
    {
        $clock = new MockClock('2026-11-28 12:00:00 UTC');
        $identity = new RecordedInstallation($clock, 'installation-secret')->client();
        $jwt = new AppJwt(self::APP_ID, AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $identity, new NullLogger()), '678', $clock);
        $expected = ExpectedInstallation::fromSettings($permissions, 'acme/widget,acme/gadget');
        $readings = FreshReadings::at($clock);
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($identity, $readings, $tokens)), $expected, new NullLogger());
        $calls = new GitHubCalls(new MockHttpClient($this->answer(...), 'https://api.github.com'), $readings, $tokens);

        return new PartIssueCalls($calls, new CheckedInstallationTokens($readBack, $tokens), new NextLinks());
    }

    /**
     * @param array<mixed> $options
     */
    private function answer(string $method, string $url, array $options): ResponseInterface
    {
        $this->requests[] = $method.' '.$url;

        if (null !== $this->refusal) {
            return new MockResponse('{"message":"Refused"}', ['http_code' => $this->refusal]);
        }

        $one = $this->indexIn($url);

        if (null !== $one) {
            return $this->answerAbout($one, $method, $options);
        }

        if ('GET' === $method) {
            return $this->listing($url);
        }

        return $this->created($options);
    }

    private function indexIn(string $url): ?int
    {
        return 1 === preg_match('~/issues/(\d+)$~', (string) parse_url($url, PHP_URL_PATH), $matches) ? (int) $matches[1] - 1 : null;
    }

    // An edit keeps what GitHub would store otherwise too, so a test can make GitHub drop the marker on every write.
    /**
     * @param array<mixed> $options
     */
    private function answerAbout(int $index, string $method, array $options): ResponseInterface
    {
        if (!array_key_exists($index, $this->issues)) {
            return new MockResponse('{"message":"Not Found"}', ['http_code' => 404]);
        }

        if ('PATCH' === $method) {
            $body = $options['body'] ?? '';
            $this->issues[$index] = [...$this->issues[$index], ...(array) json_decode(is_string($body) ? $body : '', true, flags: JSON_THROW_ON_ERROR), ...$this->storedOtherwise];
        }

        return new MockResponse(json_encode($this->issues[$index], JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }

    private function listing(string $url): ResponseInterface
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $page = is_numeric($query['page'] ?? null) ? (int) $query['page'] : 1;

        $headers = $page * $this->served < count($this->issues) ? ['link: <https://api.github.com/repos/acme/widget/issues?page='.($page + 1).'>; rel="next"'] : [];

        return new MockResponse(json_encode(array_slice($this->issues, ($page - 1) * $this->served, $this->served), JSON_THROW_ON_ERROR), ['http_code' => 200, 'response_headers' => $headers]);
    }

    /**
     * @param array<mixed> $options
     */
    private function created(array $options): ResponseInterface
    {
        if (null !== $this->answerToAnUnstoredCreate) {
            return new MockResponse('{"message":"Refused"}', ['http_code' => $this->answerToAnUnstoredCreate, 'response_headers' => $this->headersOfAnUnstoredCreate]);
        }

        $body = $options['body'] ?? '';
        $payload = (array) json_decode(is_string($body) ? $body : '', true, flags: JSON_THROW_ON_ERROR);
        $labels = is_array($payload['labels'] ?? null) ? $payload['labels'] : [];
        // Stamped with the App's id, because GitHub stamps an issue created with an installation token so.
        $stored = [...$payload, 'number' => count($this->issues) + 1, 'labels' => array_map(static fn (mixed $label): array => ['name' => $label], $labels), 'user' => ['login' => 'intakt[bot]', 'type' => 'Bot'], 'created_at' => self::MOMENT, 'performed_via_github_app' => ['id' => (int) self::APP_ID], ...$this->storedOtherwise];
        $this->issues[] = $stored;

        if (null !== $this->answerToAStoredCreate) {
            return new MockResponse('{"message":"Bad Gateway"}', ['http_code' => $this->answerToAStoredCreate]);
        }

        return new MockResponse(json_encode($stored, JSON_THROW_ON_ERROR), ['http_code' => 201]);
    }
}
