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
use JsonException;
use stdClass;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

// The reads and the delete of branch cleanup, each answer checked for its shape, because a branch read wrongly could be deleted.
final readonly class GitHubBranchCalls
{
    private const int PAGE_SIZE = 100;
    // A listing of more pages than this fails, because a `next` on every page would otherwise be read without end.
    private const int MAX_PAGES = 100;
    private const int OK = 200;
    private const int NO_CONTENT = 204;
    private const int UNAUTHORIZED = 401;
    private const int NOT_FOUND = 404;

    public function __construct(
        private GitHubCalls $calls,
        private CheckedInstallationTokens $tokens,
        private NextLinks $nextLinks,
    ) {
    }

    /**
     * @return array<mixed>
     *
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function object(string $path): array
    {
        return $this->objectOf($this->decode($this->exchange($path, [])['body']));
    }

    /**
     * @param array<string, string> $query
     *
     * @return list<mixed>
     *
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function listing(string $path, array $query): array
    {
        return array_merge([], ...array_map($this->listOf(...), $this->pages($path, $query)));
    }

    // Every page must carry the same total as the commits read, because a comparison that ends early would hide a person's commit.
    /**
     * @return list<mixed>
     *
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function comparison(string $path): array
    {
        $pages = array_map($this->objectOf(...), $this->pages($path, []));
        $commits = array_merge([], ...array_map(fn (array $page): array => $this->listOf($page['commits'] ?? null), $pages));
        if (array_any($pages, static fn (array $page): bool => ($page['total_commits'] ?? null) !== count($commits))) {
            throw new ForgeReadFailed('GitHub answered a comparison whose commits do not add up to its total.');
        }

        return $commits;
    }

    /**
     * @param array<mixed> $data
     *
     * @throws ForgeReadFailed
     */
    public function text(array $data, string ...$keys): string
    {
        $value = $data;
        foreach ($keys as $key) {
            $value = is_array($value) ? ($value[$key] ?? null) : null;
        }

        return is_string($value) && '' !== $value ? $value : throw new ForgeReadFailed(sprintf('GitHub answered without "%s".', implode('.', $keys)));
    }

    /**
     * @throws ForgeWriteFailed
     * @throws ForgeRateLimited
     */
    public function delete(string $path): void
    {
        // Never retried by the client, because a retry would delete without reading the head again, after the branch may have moved.
        try {
            $status = $this->calls->asInstallation('DELETE', $path, ['auth_bearer' => $this->bearer(), 'headers' => ['Accept' => 'application/vnd.github+json'], 'max_retries' => 0])->getStatusCode();
        } catch (ExceptionInterface $failure) {
            throw $this->unsent($failure::class);
        } catch (ForgeReadFailed $noToken) {
            throw new ForgeWriteFailed($noToken->getMessage(), $noToken->getCode(), $noToken);
        }

        if (self::NO_CONTENT !== $status) {
            throw new ForgeWriteFailed(sprintf('GitHub answered %d to a branch deletion.', $status));
        }
    }

    // Only 200 and 404 answer the question, because any other status leaves open whether the branch exists, and a restore must not push over one.
    /**
     * @throws ForgeReadFailed
     * @throws ForgeRateLimited
     */
    public function exists(string $path): bool
    {
        try {
            $status = $this->calls->asInstallation('GET', $path, ['auth_bearer' => $this->bearer(), 'headers' => ['Accept' => 'application/vnd.github+json']])->getStatusCode();
        } catch (ExceptionInterface $failure) {
            throw $this->unreachable($failure::class);
        }

        return match ($status) {
            self::OK => true,
            self::NOT_FOUND => false,
            default => throw new ForgeReadFailed(sprintf('GitHub answered %d to a read.', $status), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null),
        };
    }

    // The installation token for a git push, because git reaches GitHub over https with it as the password of an x-access-token login.
    /**
     * @throws ForgeReadFailed
     */
    public function pushToken(): string
    {
        return $this->bearer();
    }

    /**
     * @param array<string, string> $query
     *
     * @return array{body: string, links: list<string>}
     */
    private function exchange(string $path, array $query): array
    {
        try {
            $response = $this->calls->asInstallation('GET', $path, ['auth_bearer' => $this->bearer(), 'query' => $query, 'headers' => ['Accept' => 'application/vnd.github+json']]);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
            $links = $response->getHeaders(false)['link'] ?? [];
        } catch (ExceptionInterface $failure) {
            throw $this->unreachable($failure::class);
        }

        // Only a whole answer counts, because another 2xx such as `206 Partial Content` would read part of a listing as all of it.
        if (self::OK !== $status) {
            throw new ForgeReadFailed(sprintf('GitHub answered %d to a read.', $status), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null);
        }

        return ['body' => $body, 'links' => $links];
    }

    private function bearer(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted $notMinted) {
            throw new ForgeReadFailed('No GitHub installation token for a branch call.', $notMinted->getCode(), previous: $notMinted);
        } catch (InstallationNotReadBack|InstallationDoesNotMatchConfiguration $refused) {
            throw new ForgeReadFailed('No GitHub branch call before the installation is confirmed: '.$refused->getMessage(), $refused->getCode(), previous: $refused);
        }
    }

    // Not chained to the client's exception, because its trace holds the request options with the bearer token.
    private function unreachable(string $failure): ForgeReadFailed
    {
        return new ForgeReadFailed(sprintf('GitHub could not be reached (%s).', $failure));
    }

    private function decode(string $body): mixed
    {
        try {
            return $this->typed(json_decode($body, false, flags: JSON_THROW_ON_ERROR));
        } catch (JsonException $invalid) {
            throw new ForgeReadFailed('GitHub answered something other than JSON.', $invalid->getCode(), previous: $invalid);
        }
    }

    // An object whose fields read as a list (`{}`, or keys "0", "1" and so on) becomes null, because PHP would turn it into a list that passes for the branches, commits, labels or pull requests; every object read here has named fields.
    private function typed(mixed $value): mixed
    {
        return match (true) {
            $value instanceof stdClass => array_is_list(get_object_vars($value)) ? null : array_map($this->typed(...), get_object_vars($value)),
            is_array($value) => array_map($this->typed(...), $value),
            default => $value,
        };
    }

    /**
     * @return array<mixed>
     */
    private function objectOf(mixed $data): array
    {
        return is_array($data) && !array_is_list($data) ? $data : throw new ForgeReadFailed('GitHub answered something other than an object with fields where one was expected.');
    }

    /**
     * @return list<mixed>
     */
    private function listOf(mixed $data): array
    {
        return is_array($data) && array_is_list($data) ? $data : throw new ForgeReadFailed('GitHub answered something other than a list where one was expected.');
    }

    // Every page up to the cap, because a pull request on a later page may be the open or kept one; the page number is counted here, so a `next` link can only say whether to go on.
    /**
     * @param array<string, string> $query
     *
     * @return list<mixed>
     */
    private function pages(string $path, array $query): array
    {
        $pages = [];
        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $paged = [...$query, 'per_page' => (string) self::PAGE_SIZE, 'page' => (string) $page];
            $answer = $this->exchange($path, $paged);
            $pages[] = $this->decode($answer['body']);
            if (!$this->nextLinks->namesNext($answer['links'], $path.'?'.http_build_query($paged, '', '&'))) {
                return $pages;
            }
        }

        throw new ForgeReadFailed(sprintf('GitHub lists more than %d pages, more than this forge reads.', self::MAX_PAGES));
    }

    // Not chained to the client's exception, because its trace holds the request options with the bearer token.
    private function unsent(string $failure): ForgeWriteFailed
    {
        return new ForgeWriteFailed(sprintf('GitHub could not be reached (%s).', $failure));
    }
}
