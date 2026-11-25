<?php

declare(strict_types=1);

namespace Intakt\Tests\Plugin\GitHubForge;

use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Uri\Rfc3986\Uri;

// Apart from RecordedInstallation, because the trust tests also need the App's own answer and a permission answer per account and the author of each pull request.
final class RecordedTrust
{
    public const string WRITER = 'alice';
    public const string TRIAGE = 'mallory';
    public const string READ = 'rita';
    public const string BOT_BY_TYPE = 'ci-machine';
    public const string OWN_IDENTITY = 'acme-intakt[bot]';
    public const string MAINTAINER_SLUG = 'acme-intakt-maintainer';
    public const string MAINTAINER = 'acme-intakt-maintainer[bot]';
    public const string FOREIGN_BOT = 'dependabot[bot]';
    public const string READER_SLUG = 'acme-reader';
    public const string READER = 'acme-reader[bot]';

    private const array PERMISSIONS = [
        self::WRITER => 'permission_writer.json',
        self::TRIAGE => 'permission_triage.json',
        self::READ => 'permission_reader.json',
        self::BOT_BY_TYPE => 'permission_bot_type.json',
        self::OWN_IDENTITY => 'permission_own_identity.json',
        self::MAINTAINER => 'permission_maintainer.json',
        self::READER => 'permission_bot_read.json',
        'impostor' => 'permission_other_account.json',
        'carol' => 'permission_unknown_level.json',
    ];

    public const string PULL_BY_WRITER = 'https://github.com/acme/widget/pull/1';
    public const string PULL_BY_TRIAGE = 'https://github.com/acme/widget/pull/2';
    public const string PULL_BY_FOREIGN_BOT = 'https://github.com/acme/widget/pull/3';
    public const string PULL_BY_OWN_IDENTITY = 'https://github.com/acme/widget/pull/4';
    public const string PULL_BY_MAINTAINER = 'https://github.com/acme/widget/pull/5';
    public const string PULL_BY_STRANGER = 'https://github.com/acme/widget/pull/6';
    public const string PULL_WITHOUT_AUTHOR = 'https://github.com/acme/widget/pull/7';
    public const string PULL_WITH_EMPTY_AUTHOR = 'https://github.com/acme/widget/pull/8';
    public const string PULL_FROM_FORK = 'https://github.com/acme/widget/pull/9';
    public const string PULL_FROM_DELETED_FORK = 'https://github.com/acme/widget/pull/10';
    public const string PULL_WITHOUT_HEAD_REPO = 'https://github.com/acme/widget/pull/11';
    public const string PULL_UNKNOWN = 'https://github.com/acme/widget/pull/99';

    private const array PULLS = [
        1 => 'pull_by_writer.json',
        2 => 'pull_by_triage.json',
        3 => 'pull_by_foreign_bot.json',
        4 => 'pull_by_own_identity.json',
        5 => 'pull_by_maintainer.json',
        6 => 'pull_by_stranger.json',
        7 => 'pull_without_author.json',
        8 => 'pull_with_empty_author.json',
        9 => 'pull_from_fork.json',
        10 => 'pull_from_deleted_fork.json',
        11 => 'pull_without_head_repo.json',
    ];

    /** @var list<string> */
    public array $requested = [];

    /** @var array<string, string> */
    public array $credentials = [];

    public function __construct(
        private readonly ClockInterface $readerClock,
        private readonly int $tokenStatus = 201,
    ) {
    }

    public function client(): MockHttpClient
    {
        return new MockHttpClient(fn (string $method, string $url, array $options): ResponseInterface => $this->answer($url, $options), 'https://api.github.com');
    }

    /**
     * @param array<mixed> $options
     */
    private function answer(string $url, array $options): ResponseInterface
    {
        $this->requested[] = $url;
        $this->credentials[$url] = $this->bearerOf($options);
        $path = new Uri($url)->getPath();

        return match (true) {
            str_ends_with($path, '/access_tokens') => $this->token(),
            '/app' === $path => $this->fixture('app.json'),
            str_starts_with($path, '/app/installations/') => $this->fixture('installation.json'),
            str_starts_with($path, '/installation/repositories') => $this->fixture('installation_repositories.json'),
            str_starts_with($path, '/repos/acme/widget/pulls/') => $this->pull($path),
            default => $this->permission($path),
        };
    }

    /**
     * @param array<mixed> $options
     */
    private function bearerOf(array $options): string
    {
        $normalized = $options['normalized_headers'] ?? null;
        $headers = is_array($normalized) ? ($normalized['authorization'] ?? null) : null;

        return is_array($headers) && is_string($headers[0] ?? null) ? $headers[0] : '';
    }

    private function token(): MockResponse
    {
        if (201 !== $this->tokenStatus) {
            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/access_tokens_401.json'), ['http_code' => $this->tokenStatus]);
        }

        return new MockResponse(json_encode(['token' => 'minted-value', 'expires_at' => RecordedTokenExpiry::anHourAfter($this->readerClock)], JSON_THROW_ON_ERROR), ['http_code' => 201]);
    }

    private function fixture(string $name): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$name));
    }

    private function pull(string $path): MockResponse
    {
        $fixture = self::PULLS[(int) substr($path, strlen('/repos/acme/widget/pulls/'))] ?? null;

        return null === $fixture ? new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/not_found.json'), ['http_code' => 404]) : $this->fixture($fixture);
    }

    private function permission(string $path): MockResponse
    {
        $matched = preg_match('#^/repos/acme/widget/collaborators/([^/]+)/permission$#', $path, $parts);
        $fixture = 1 === $matched ? (self::PERMISSIONS[strtolower(rawurldecode($parts[1]))] ?? null) : null;

        return null === $fixture ? new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/not_found.json'), ['http_code' => 404]) : $this->fixture($fixture);
    }
}
