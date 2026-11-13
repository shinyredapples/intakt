<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Domain\IssueComment;
use JsonException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

// Every comment of an issue or a failure, because an owner's approval on a page left unread would read as no approval, and a request on one as none posted.
final readonly class GitHubIssueComments
{
    private const int PAGE_SIZE = 100;
    private const int MAX_PAGES = 30;
    private const int UNAUTHORIZED = 401;
    private const string PERSON = 'User';

    public function __construct(
        private GitHubCalls $calls,
        private CheckedInstallationTokens $tokens,
        private WatchedRepositories $repositories,
        #[Autowire(env: 'GITHUB_APP_ID')]
        private string $appId,
    ) {
    }

    /**
     * @return list<IssueComment>
     *
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function of(string $project, int $issue): array
    {
        $path = $this->commentsPath($project, $issue);
        $comments = [];
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $listed = $this->page($path, $page);
            $comments = [...$comments, ...array_map($this->comment(...), $listed)];
            if (count($listed) < self::PAGE_SIZE) {
                return $comments;
            }
        }

        throw new ForgeReadFailed('The issue holds more comments than this forge reads.');
    }

    /**
     * @throws ForgeWriteFailed
     * @throws ForgeRateLimited
     */
    public function post(string $project, int $issue, string $body): void
    {
        try {
            $path = $this->commentsPath($project, $issue);
        } catch (ForgeReadFailed $unknown) {
            throw new ForgeWriteFailed($unknown->getMessage(), previous: $unknown);
        }

        // Never retried, because GitHub can store a comment and still answer 502, and a retry would post it twice.
        $options = ['auth_bearer' => $this->writeBearer(), 'json' => ['body' => $body], 'headers' => ['Accept' => 'application/vnd.github+json'], 'max_retries' => 0];

        try {
            $response = $this->calls->asInstallation('POST', $path, $options);
            $status = $response->getStatusCode();
            $response->getContent(false);
        } catch (ExceptionInterface $failure) {
            throw new ForgeWriteFailed(sprintf('GitHub could not be reached for a write (%s).', $failure::class));
        }

        if ($status < 200 || $status >= 300) {
            throw new ForgeWriteFailed(sprintf('GitHub answered %d to a write.', $status), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null);
        }
    }

    /**
     * @throws ForgeReadFailed
     */
    private function commentsPath(string $project, int $issue): string
    {
        return $issue > 0 ? $this->repositories->addressOf($project)->path().'/issues/'.$issue.'/comments' : throw new ForgeReadFailed(sprintf('%d is no issue number.', $issue));
    }

    /**
     * @return list<array<mixed>>
     *
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    private function page(string $path, int $page): array
    {
        $options = ['auth_bearer' => $this->readBearer(), 'query' => ['per_page' => self::PAGE_SIZE, 'page' => $page], 'headers' => ['Accept' => 'application/vnd.github+json']];

        try {
            $response = $this->calls->asInstallation('GET', $path, $options);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $failure) {
            // Not chained to the client's exception, because its trace holds the request options with the bearer token.
            throw new ForgeReadFailed(sprintf('GitHub could not be reached (%s).', $failure::class));
        }

        if ($status < 200 || $status >= 300) {
            throw new ForgeReadFailed(sprintf('GitHub answered %d to a read of the comments.', $status), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null);
        }

        return $this->listIn($body);
    }

    /**
     * @return list<array<mixed>>
     *
     * @throws ForgeReadFailed
     */
    private function listIn(string $body): array
    {
        try {
            $comments = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new ForgeReadFailed('GitHub listed the comments as something other than JSON.', $invalid->getCode(), previous: $invalid);
        }

        // `{}` decodes to the same empty array as `[]`, so only the answer's first character tells an empty object from an empty list.
        if (!is_array($comments) || !array_is_list($comments) || !str_starts_with(ltrim($body), '[')) {
            throw new ForgeReadFailed('GitHub answered a comment listing that is not a list.');
        }

        return array_map(static fn (mixed $comment): array => is_array($comment) ? $comment : throw new ForgeReadFailed('GitHub listed a comment that is not an object.'), $comments);
    }

    /**
     * @param array<mixed> $comment
     *
     * @throws ForgeReadFailed
     */
    private function comment(array $comment): IssueComment
    {
        $user = $comment['user'] ?? null;
        $login = is_array($user) ? ($user['login'] ?? null) : null;
        $type = is_array($user) ? ($user['type'] ?? null) : null;
        $body = $comment['body'] ?? null;
        $created = $comment['created_at'] ?? null;
        $updated = $comment['updated_at'] ?? null;
        if (!is_string($login) || '' === $login || !is_string($type) || !is_string($body) || !is_string($created) || !is_string($updated)) {
            throw new ForgeReadFailed('GitHub listed a comment without its author, text or times.');
        }

        // Posted through this App, which GitHub alone sets, because anyone can write the request's marker into a comment of their own.
        $app = $comment['performed_via_github_app'] ?? null;
        $appId = is_array($app) ? ($app['id'] ?? null) : null;

        return new IssueComment($login, self::PERSON === $type, $created !== $updated, is_int($appId) && (string) $appId === $this->appId, $body);
    }

    /**
     * @throws ForgeReadFailed
     */
    private function readBearer(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted|InstallationNotReadBack|InstallationDoesNotMatchConfiguration $refused) {
            throw new ForgeReadFailed('No GitHub read of the comments: '.$refused->getMessage(), $refused->getCode(), previous: $refused);
        }
    }

    /**
     * @throws ForgeWriteFailed
     */
    private function writeBearer(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted|InstallationNotReadBack|InstallationDoesNotMatchConfiguration $refused) {
            throw new ForgeWriteFailed('No GitHub write of a comment: '.$refused->getMessage(), $refused->getCode(), previous: $refused);
        }
    }
}
