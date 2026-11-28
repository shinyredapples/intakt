<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Intakt\Plugin\GitHubForge\Domain\ExpectedInstallation;
use Intakt\Plugin\GitHubForge\Domain\TaskIssue;
use Intakt\Plugin\GitHubForge\Infrastructure\AppJwt;
use Intakt\Plugin\GitHubForge\Infrastructure\CheckedInstallationTokens;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubCalls;
use Intakt\Plugin\GitHubForge\Infrastructure\GitHubIssueComments;
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

// One issue of acme/widget on GitHub: lists its comments a page at a time and keeps each post as a comment of the App, so a test sees what the owner would see.
final class ScriptedIssue
{
    public const string APP_ID = '12345';
    public const string PROJECT = 'widget';
    public const int NUMBER = 7;
    private const string MOMENT = '2026-11-25T10:00:00Z';

    /** @var list<array<mixed>> */
    public array $comments = [];

    /** @var list<string> */
    public array $requests = [];

    public ?int $refusal = null;

    // What GitHub stores other than what was posted, for a post GitHub does not keep as sent.
    /** @var array<string, mixed> */
    public array $storedOtherwise = [];

    // The status GitHub answers to a post it stored all the same, as it can with a 502.
    public ?int $answerToAStoredPost = null;

    public static function task(): TaskIssue
    {
        return new TaskIssue(self::PROJECT, self::NUMBER);
    }

    public function byPerson(string $body, string $login = 'owner'): void
    {
        $this->comments[] = ['body' => $body, 'user' => ['login' => $login, 'type' => 'User'], 'created_at' => self::MOMENT, 'updated_at' => self::MOMENT, 'performed_via_github_app' => null];
    }

    // An App acting with the owner's own token keeps the owner's login and `User` type; only the App's id on the comment tells it apart.
    public function byOwnerThroughAnApp(string $body, int $app): void
    {
        $this->comments[] = ['body' => $body, 'user' => ['login' => 'owner', 'type' => 'User'], 'created_at' => self::MOMENT, 'updated_at' => self::MOMENT, 'performed_via_github_app' => ['id' => $app]];
    }

    public function editedByOwner(string $body): void
    {
        $this->comments[] = ['body' => $body, 'user' => ['login' => 'owner', 'type' => 'User'], 'created_at' => self::MOMENT, 'updated_at' => '2026-11-25T10:05:00Z', 'performed_via_github_app' => null];
    }

    /**
     * @return list<string>
     */
    public function posted(): array
    {
        $own = array_filter($this->comments, static fn (array $comment): bool => null !== $comment['performed_via_github_app']);

        return array_values(array_map(static fn (array $comment): string => is_string($comment['body']) ? $comment['body'] : '', $own));
    }

    public function comments(): GitHubIssueComments
    {
        $clock = new MockClock('2026-11-25 10:00:00 UTC');
        $identity = new RecordedInstallation($clock, 'installation-secret')->client();
        $jwt = new AppJwt(self::APP_ID, AppKey::generate()->private, $clock);
        $tokens = new InstallationTokens(new InstallationTokenMinter($jwt, $identity, new NullLogger()), '678', $clock);
        $expected = ExpectedInstallation::fromSettings('contents:write,pull_requests:write,workflows:write', 'acme/widget,acme/gadget');
        $readings = FreshReadings::at($clock);
        $readBack = new InstallationReadBack(new InstallationReader($jwt, $tokens, new GitHubCalls($identity, $readings, $tokens)), $expected, new NullLogger());
        $calls = new GitHubCalls(new MockHttpClient($this->answer(...), 'https://api.github.com'), $readings, $tokens);

        return new GitHubIssueComments($calls, new CheckedInstallationTokens($readBack, $tokens), new WatchedRepositories([self::PROJECT => ['github' => 'acme/widget']]), self::APP_ID);
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

            return new MockResponse(json_encode(array_slice($this->comments, ($page - 1) * 100, 100), JSON_THROW_ON_ERROR), ['http_code' => 200]);
        }

        $body = $options['body'] ?? '';
        $payload = (array) json_decode(is_string($body) ? $body : '', true, flags: JSON_THROW_ON_ERROR);
        // Stamped with the App's id, because GitHub stamps a comment posted with an installation token so.
        $stored = [...$payload, 'user' => ['login' => 'intakt[bot]', 'type' => 'Bot'], 'created_at' => self::MOMENT, 'updated_at' => self::MOMENT, 'performed_via_github_app' => ['id' => (int) self::APP_ID], ...$this->storedOtherwise];
        $this->comments[] = $stored;
        if (null !== $this->answerToAStoredPost) {
            return new MockResponse('{"message":"Bad Gateway"}', ['http_code' => $this->answerToAStoredPost]);
        }

        return new MockResponse(json_encode($stored, JSON_THROW_ON_ERROR), ['http_code' => 201]);
    }
}
