<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubTaskParts;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReader;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokenMinter;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\WatchedRepositories;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
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

    // Answered after the issue is kept, because GitHub can create an issue and still answer 502.
    public ?int $answerToAStoredCreate = null;

    // What GitHub stores other than what was sent, for a create GitHub does not keep as sent.
    /** @var array<string, mixed> */
    public array $storedOtherwise = [];

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

    public function parts(): GitHubTaskParts
    {
        $clock = new MockClock('2026-11-28 12:00:00 UTC');
        $identity = new RecordedInstallation($clock, 'installation-secret')->client();
        $jwt = new AppJwt(self::APP_ID, AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $identity, new NullLogger()), '678', $clock);
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $readings = FreshReadings::at($clock);
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($identity, $readings, $tokens)), $expected, new NullLogger());
        $calls = new GitHubCalls(new MockHttpClient($this->answer(...), 'https://api.github.com'), $readings, $tokens);

        return new GitHubTaskParts($calls, new CheckedInstallationTokens($readBack, $tokens), new WatchedRepositories([self::PROJECT => ['github' => 'acme/widget']]), self::APP_ID);
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

        if ('GET' === $method) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            $page = is_numeric($query['page'] ?? null) ? (int) $query['page'] : 1;

            return new MockResponse(json_encode(array_slice($this->issues, ($page - 1) * 100, 100), JSON_THROW_ON_ERROR), ['http_code' => 200]);
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
