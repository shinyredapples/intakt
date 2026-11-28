<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use DateTimeZone;
use Intakt\Plugin\Forge\Contract\ForgeCredentialsRefused;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\InstallationDoesNotMatchConfiguration;
use Intakt\Plugin\GitHubForge\Contract\InstallationNotReadBack;
use Intakt\Plugin\GitHubForge\Contract\InstallationTokenNotMinted;
use Intakt\Plugin\GitHubForge\Contract\PartIssue;
use Intakt\Plugin\GitHubForge\Contract\TaskParts;
use JsonException;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

#[AsAlias(TaskParts::class)]
final readonly class GitHubTaskParts implements TaskParts
{
    private const int PAGE_SIZE = 100;
    // A search of more pages than this fails, because a part filed past them would be filed a second time.
    private const int MAX_PAGES = 10;
    private const int UNAUTHORIZED = 401;
    // Searched from a day before the plan, because GitHub's clock and this host's may differ, and a part filed by GitHub's earlier clock must still be found.
    private const string CLOCK_MARGIN = '-1 day';

    public function __construct(
        private GitHubCalls $calls,
        private CheckedInstallationTokens $tokens,
        private WatchedRepositories $repositories,
        #[Autowire(env: 'GITHUB_APP_ID')]
        private string $appId,
    ) {
    }

    #[Override]
    public function fileOnce(string $project, PartIssue $part): int
    {
        $path = $this->repositories->addressOf($project)->path().'/issues';

        return $this->filedBefore($path, $part) ?? $this->filed($path, $part);
    }

    // By this App's mark and the marker, never by the title, because anyone can write the marker into an issue of their own, and two parts may share a title.
    /**
     * @throws ForgeReadFailed
     */
    private function filedBefore(string $path, PartIssue $part): ?int
    {
        $since = $part->plannedAt->modify(self::CLOCK_MARGIN)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $listed = $this->page($path, ['state' => 'all', 'since' => $since, 'sort' => 'created', 'direction' => 'asc', 'per_page' => self::PAGE_SIZE, 'page' => $page]);
            $found = array_find($listed, fn (array $issue): bool => $this->isThisAppsPart($issue, $part->marker));

            if (null !== $found) {
                return $this->numberOf($found) ?? throw new ForgeReadFailed('GitHub listed a part without its number.');
            }

            if (count($listed) < self::PAGE_SIZE) {
                return null;
            }
        }

        throw new ForgeReadFailed('GitHub lists more issues since the split was planned than this forge reads.');
    }

    /**
     * @param array<string, int|string> $query
     *
     * @return list<array<mixed>>
     *
     * @throws ForgeReadFailed
     */
    private function page(string $path, array $query): array
    {
        $options = ['auth_bearer' => $this->bearer(), 'query' => $query, 'headers' => ['Accept' => 'application/vnd.github+json']];

        try {
            $response = $this->calls->asInstallation('GET', $path, $options);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $failure) {
            throw $this->unreachable($failure::class);
        }

        if ($status < 200 || $status >= 300) {
            throw new ForgeReadFailed(sprintf('GitHub answered %d to a read of the issues.', $status), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null);
        }

        return $this->listIn($body);
    }

    /**
     * @throws ForgeReadFailed
     */
    private function bearer(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted|InstallationNotReadBack|InstallationDoesNotMatchConfiguration $refused) {
            throw new ForgeReadFailed('No GitHub read of the issues: '.$refused->getMessage(), $refused->getCode(), previous: $refused);
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
            $issues = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new ForgeReadFailed('GitHub listed the issues as something other than JSON.', $invalid->getCode(), previous: $invalid);
        }

        // `{}` decodes to the same empty array as `[]`, so only the answer's first character tells an empty object from an empty list.
        if (!is_array($issues) || !array_is_list($issues) || !str_starts_with(ltrim($body), '[')) {
            throw new ForgeReadFailed('GitHub answered an issue listing that is not a list.');
        }

        return array_map(static fn (mixed $issue): array => is_array($issue) ? $issue : throw new ForgeReadFailed('GitHub listed an issue that is not an object.'), $issues);
    }

    /**
     * @param array<mixed> $issue
     */
    private function isThisAppsPart(array $issue, string $marker): bool
    {
        $body = $issue['body'] ?? null;

        return !array_key_exists('pull_request', $issue) && $this->byThisApp($issue) && is_string($body) && str_contains($body, $marker);
    }

    // Created through this App, which GitHub alone sets, because anyone can write the marker into an issue of their own.
    /**
     * @param array<mixed> $issue
     */
    private function byThisApp(array $issue): bool
    {
        $app = $issue['performed_via_github_app'] ?? null;
        $id = is_array($app) ? ($app['id'] ?? null) : null;

        return is_int($id) && (string) $id === $this->appId;
    }

    /**
     * @param array<mixed> $issue
     */
    private function numberOf(array $issue): ?int
    {
        $number = $issue['number'] ?? null;

        return is_int($number) && $number > 0 ? $number : null;
    }

    /**
     * @throws ForgeWriteFailed
     */
    private function filed(string $path, PartIssue $part): int
    {
        // Never retried, because GitHub can create an issue and still answer 502, and a retry would file it twice.
        $options = ['auth_bearer' => $this->writeBearer(), 'json' => ['title' => $part->title, 'body' => $part->body."\n\n".$part->marker, 'labels' => [...$part->labels]], 'headers' => ['Accept' => 'application/vnd.github+json'], 'max_retries' => 0];

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

        return $this->confirmedNumber($stored, $part->marker);
    }

    /**
     * @throws ForgeWriteFailed
     */
    private function writeBearer(): string
    {
        try {
            return $this->tokens->current()->value;
        } catch (InstallationTokenNotMinted|InstallationNotReadBack|InstallationDoesNotMatchConfiguration $refused) {
            throw new ForgeWriteFailed('No GitHub write of an issue: '.$refused->getMessage(), $refused->getCode(), previous: $refused);
        }
    }

    // Not chained either, because the trace of a failed create holds the bearer token too.
    private function unreachableForWrite(string $failure): ForgeWriteFailed
    {
        return new ForgeWriteFailed(sprintf('GitHub could not be reached for a write (%s).', $failure));
    }

    // Checked on every create, because a later run finds the part only by this App's mark and the marker in its body, and a part it cannot find would be filed again.
    /**
     * @throws ForgeWriteFailed
     */
    private function confirmedNumber(string $answer, string $marker): int
    {
        try {
            $issue = json_decode($answer, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new ForgeWriteFailed('GitHub answered the create with something other than JSON.', $invalid->getCode(), previous: $invalid);
        }

        $number = is_array($issue) ? $this->numberOf($issue) : null;

        if (!is_array($issue) || null === $number) {
            throw new ForgeWriteFailed('GitHub answered the create without the number of the issue.');
        }

        return $this->isThisAppsPart($issue, $marker) ? $number : throw new ForgeWriteFailed('GitHub stored the part without this App\'s mark or its marker, so no retry would find it.');
    }
}
