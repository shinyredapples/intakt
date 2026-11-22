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
use Intakt\Plugin\GitHubForge\Domain\TaskIssue;
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
    public function of(TaskIssue $issue): array
    {
        $path = $this->commentsPath($issue);
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
    public function post(TaskIssue $issue, string $body): void
    {
        try {
            $path = $this->commentsPath($issue);
        } catch (ForgeReadFailed $unknown) {
            throw new ForgeWriteFailed($unknown->getMessage(), $unknown->getCode(), previous: $unknown);
        }

        // Never retried, because GitHub can store a comment and still answer 502, and a retry would post it twice.
        $options = ['auth_bearer' => $this->writeBearer(), 'json' => ['body' => $body], 'headers' => ['Accept' => 'application/vnd.github+json'], 'max_retries' => 0];

        try {
            $response = $this->calls->asInstallation('POST', $path, $options);
            $status = $response->getStatusCode();
            $stored = $response->getContent(false);
        } catch (ExceptionInterface $failure) {
            throw $this->unreachableForWrite($failure::class);
        }

        if ($status < 200 || $status >= 300) {
            throw new ForgeWriteFailed(sprintf('GitHub answered %d to a write.', $status), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null);
        }

        $this->confirmStored($stored, $body);
    }

    /**
     * @throws ForgeReadFailed
     */
    private function commentsPath(TaskIssue $issue): string
    {
        return $issue->number > 0 ? $this->repositories->addressOf($issue->project)->path().'/issues/'.$issue->number.'/comments' : throw new ForgeReadFailed(sprintf('%d is no issue number.', $issue->number));
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
            throw $this->unreachable($failure::class);
        }

        if ($status < 200 || $status >= 300) {
            throw new ForgeReadFailed(sprintf('GitHub answered %d to a read of the comments.', $status), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null);
        }

        return $this->listIn($body);
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

        // An App acting with a person's token keeps that person's login and `User` type, so only a comment with no App on it is the person's own.
        return new IssueComment($login, self::PERSON === $type && null === $app, $created !== $updated, is_int($appId) && (string) $appId === $this->appId, $body);
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

    // Not chained to the client's exception, because its trace holds the request options with the bearer token.
    private function unreachable(string $failure): ForgeReadFailed
    {
        return new ForgeReadFailed(sprintf('GitHub could not be reached (%s).', $failure));
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

    // Not chained either, because the trace of a failed post holds the bearer token too.
    private function unreachableForWrite(string $failure): ForgeWriteFailed
    {
        return new ForgeWriteFailed(sprintf('GitHub could not be reached for a write (%s).', $failure));
    }

    /**
     * @throws ForgeWriteFailed
     */
    // Checked on every post, because a later run finds the request only by this App's mark, its unchanged times and its exact text, and a comment GitHub keeps otherwise would leave the owner's approve matching nothing.
    private function confirmStored(string $answer, string $posted): void
    {
        try {
            $decoded = json_decode($answer, true, flags: JSON_THROW_ON_ERROR);
            $stored = $this->comment(is_array($decoded) ? $decoded : throw new ForgeReadFailed('GitHub answered something other than a comment.'));
        } catch (JsonException|ForgeReadFailed $unread) {
            throw new ForgeWriteFailed('GitHub answered the post without the comment it stored: '.($unread instanceof JsonException ? 'the answer is not JSON.' : $unread->getMessage()), $unread->getCode(), previous: $unread);
        }

        if (!$stored->byThisApp) {
            throw new ForgeWriteFailed('GitHub stored the comment without this App\'s mark, so no run would find it as the request.');
        }

        if ($stored->edited) {
            throw new ForgeWriteFailed('GitHub stored the comment with a change time apart from its post time, so every run would read it as edited.');
        }

        if ($posted !== $stored->body) {
            throw new ForgeWriteFailed('GitHub stored other text than was posted, so no run would match it with the request.');
        }
    }
}
