<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use JsonException;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

// Read back before a verdict comment is posted, because Messenger retries a publication whose comment GitHub already stored, and the loop expects one comment per verdict.
final readonly class PostedComments
{
    private const int PAGE_SIZE = 100;
    private const int MAX_PAGES = 30;
    private const int UNAUTHORIZED = 401;

    public function __construct(
        private GitHubCalls $calls,
        private CheckedInstallationTokens $tokens,
        private string $appId,
    ) {
    }

    /**
     * @param list<string> $comments
     *
     * @return list<string>
     */
    public function notYetPosted(PullRequestAddress $address, string $marker, array $comments): array
    {
        return [] === $comments || $this->holdsMarker($address, $marker) ? [] : $comments;
    }

    // Each marker read before the first post, which the caller's verdict lock allows, because no other delivery of the round posts meanwhile.
    /**
     * @param array<string, list<string>> $byMarker
     *
     * @return list<string>
     */
    public function bodiesNotYetPosted(PullRequestAddress $address, array $byMarker): array
    {
        $bodies = [];
        foreach ($byMarker as $marker => $comments) {
            foreach ($this->notYetPosted($address, $marker, $comments) as $comment) {
                $bodies[] = $comment."\n\n".$marker;
            }
        }

        return $bodies;
    }

    private function holdsMarker(PullRequestAddress $address, string $marker): bool
    {
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $comments = $this->page($address, $page);
            if (array_any($comments, fn (mixed $comment): bool => $this->isOwnCommentWith($comment, $marker))) {
                return true;
            }

            if (count($comments) < self::PAGE_SIZE) {
                return false;
            }
        }

        throw new ForgeWriteFailed('The pull request holds more comments than this forge reads before a post.');
    }

    /**
     * @return list<mixed>
     */
    private function page(PullRequestAddress $address, int $page): array
    {
        $options = ['auth_bearer' => $this->bearer(), 'query' => ['per_page' => self::PAGE_SIZE, 'page' => $page], 'headers' => ['Accept' => 'application/vnd.github+json']];

        try {
            $response = $this->calls->asInstallation('GET', $address->issuePath().'/comments', $options);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $failure) {
            throw $this->unreachable($failure::class);
        }

        if ($status < 200 || $status >= 300) {
            throw new ForgeWriteFailed(sprintf('GitHub answered %d to a read of the comments before a post.', $status), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null);
        }

        return $this->listIn($body);
    }

    // Posted through this App, which GitHub alone sets, because anyone who learns the round's id can write its marker into a comment of their own; the marker only at the end, where the post puts it, so a finding that quotes another round's marker never counts.
    private function isOwnCommentWith(mixed $comment, string $marker): bool
    {
        if (!is_array($comment) || !is_string($comment['body'] ?? null) || !str_ends_with(rtrim($comment['body']), $marker)) {
            return false;
        }

        $app = $comment['performed_via_github_app'] ?? null;
        $id = is_array($app) ? ($app['id'] ?? null) : null;

        return is_int($id) && (string) $id === $this->appId;
    }

    private function bearer(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted|InstallationNotReadBack|InstallationDoesNotMatchConfiguration $refused) {
            throw new ForgeWriteFailed('No GitHub read of the comments before a post: '.$refused->getMessage(), $refused->getCode(), previous: $refused);
        }
    }

    // Not chained to the client's exception, because its trace holds the request options with the bearer token.
    private function unreachable(string $failure): ForgeWriteFailed
    {
        return new ForgeWriteFailed(sprintf('GitHub could not be reached to read the comments before a post (%s).', $failure));
    }

    /**
     * @return list<mixed>
     */
    private function listIn(string $body): array
    {
        try {
            $comments = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new ForgeWriteFailed('GitHub listed the comments as something other than JSON.', $invalid->getCode(), previous: $invalid);
        }

        // `{}` decodes to the same empty array as `[]`, so only the answer's first character tells an empty object from an empty list.
        return is_array($comments) && array_is_list($comments) && str_starts_with(ltrim($body), '[') ? $comments : throw new ForgeWriteFailed('GitHub answered a comment listing that is not a list.');
    }
}
