<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use DateTimeZone;
use Intakt\Core\Ledger\Contract\Ledger;
use Intakt\Core\Ledger\Contract\LedgerEvent;
use Intakt\Core\Ledger\Contract\LedgerEventKind;
use Intakt\Core\Ledger\Contract\PayloadFields;
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
        private Ledger $ledger,
    ) {
    }

    #[Override]
    public function fileOnce(string $project, PartIssue $part): int
    {
        $path = $this->repositories->addressOf($project)->path().'/issues';
        $subject = $this->subjectOf($project, $part);
        $kept = $this->keptNumber($subject);

        return null === $kept
            ? $this->filedBefore($path, $part) ?? $this->filed($path, $part, $project, $subject)
            : $this->reconciled($path, $kept, $part);
    }

    // Length-prefixed, because a project name may end in text that looks like the start of a marker.
    private function subjectOf(string $project, PartIssue $part): string
    {
        return 'part-issue-'.hash('sha256', strlen($project).':'.$project.$part->marker);
    }

    /**
     * @throws ForgeReadFailed
     */
    private function keptNumber(string $subject): ?int
    {
        $kept = array_last([...$this->ledger->readOfKindAbout(LedgerEventKind::PartIssueCreated, $subject)]);

        if (null === $kept) {
            return null;
        }

        $number = PayloadFields::of($kept)->number('issue');

        return null !== $number && $number > 0 ? $number : throw new ForgeReadFailed('The ledger kept a created part without its issue number.');
    }

    // Without this App's mark, because the number is the answer to this App's own create, and the mark may be what that answer lacked; written only when the marker is missing, because the create may have stored it and only the answer lost it.
    /**
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     */
    private function reconciled(string $path, int $kept, PartIssue $part): int
    {
        $issue = $this->issueIn($this->read($path.'/'.$kept, []));

        if ($this->numberOf($issue) !== $kept || array_key_exists('pull_request', $issue)) {
            throw new ForgeReadFailed(sprintf('GitHub answered the read of part #%d with another issue.', $kept));
        }

        $body = $issue['body'] ?? null;
        $stored = is_string($body) ? $body : '';

        if (str_contains($stored, $part->marker)) {
            return $kept;
        }

        $marked = ('' === trim($stored) ? $part->body : $stored)."\n\n".$part->marker;
        $answer = $this->written('PATCH', $path.'/'.$kept, ['body' => $marked]);
        $written = $answer['body'] ?? null;

        return is_string($written) && str_contains($written, $part->marker) ? $kept : throw new ForgeWriteFailed(sprintf('GitHub did not keep the marker written into part #%d.', $kept));
    }

    /**
     * @return array<mixed>
     *
     * @throws ForgeReadFailed
     */
    private function issueIn(string $body): array
    {
        try {
            $issue = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new ForgeReadFailed('GitHub answered the read of an issue with something other than JSON.', $invalid->getCode(), previous: $invalid);
        }

        return is_array($issue) && str_starts_with(ltrim($body), '{') ? $issue : throw new ForgeReadFailed('GitHub answered the read of an issue with something other than an issue.');
    }

    // By this App's mark and the marker, never by the title, because anyone can write the marker into an issue of their own, and two parts may share a title.
    /**
     * @throws ForgeReadFailed
     */
    private function filedBefore(string $path, PartIssue $part): ?int
    {
        $since = $part->plannedAt->modify(self::CLOCK_MARGIN)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $listed = $this->listIn($this->read($path, ['state' => 'all', 'since' => $since, 'sort' => 'created', 'direction' => 'asc', 'per_page' => self::PAGE_SIZE, 'page' => $page]));
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
     * @throws ForgeReadFailed
     */
    private function read(string $path, array $query): string
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

        return $body;
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

    // Kept before the answer is checked for the marker, because GitHub may have stored a part whose answer lacks it, and the next run must check that issue instead of filing the part again.
    /**
     * @throws ForgeWriteFailed
     */
    private function filed(string $path, PartIssue $part, string $project, string $subject): int
    {
        $issue = $this->written('POST', $path, ['title' => $part->title, 'body' => $part->body."\n\n".$part->marker, 'labels' => [...$part->labels]]);
        $number = $this->numberOf($issue) ?? throw new ForgeWriteFailed('GitHub answered the create without the number of the issue.');
        $appended = $this->ledger->append(new LedgerEvent(LedgerEventKind::PartIssueCreated, json_encode(['project' => $project, 'issue' => $number, 'marker' => $part->marker], JSON_THROW_ON_ERROR), $subject));

        if (!$appended->succeeded()) {
            throw new ForgeWriteFailed(sprintf('GitHub filed part #%d, but the ledger did not keep its number: %s', $number, (string) $appended->failure));
        }

        return $this->isThisAppsPart($issue, $part->marker) ? $number : throw new ForgeWriteFailed(sprintf('GitHub answered the create of part #%d without this App\'s mark or its marker, so the next run checks that issue.', $number));
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<mixed>
     *
     * @throws ForgeWriteFailed
     */
    private function written(string $method, string $path, array $payload): array
    {
        // Never retried, because GitHub can create an issue and still answer 502, and a retry would file it twice.
        $options = ['auth_bearer' => $this->writeBearer(), 'json' => $payload, 'headers' => ['Accept' => 'application/vnd.github+json'], 'max_retries' => 0];

        try {
            $response = $this->calls->asInstallation($method, $path, $options);
            $status = $response->getStatusCode();
            $stored = $response->getContent(false);
        } catch (ExceptionInterface $failure) {
            throw $this->unreachableForWrite($failure::class);
        }

        if ($status < 200 || $status >= 300) {
            throw new ForgeWriteFailed(sprintf('GitHub answered %d to a write.', $status), previous: self::UNAUTHORIZED === $status ? new ForgeCredentialsRefused() : null);
        }

        return $this->answeredIssue($stored);
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

    /**
     * @return array<mixed>
     *
     * @throws ForgeWriteFailed
     */
    private function answeredIssue(string $answer): array
    {
        try {
            $issue = json_decode($answer, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new ForgeWriteFailed('GitHub answered the write with something other than JSON.', $invalid->getCode(), previous: $invalid);
        }

        return is_array($issue) && str_starts_with(ltrim($answer), '{') ? $issue : throw new ForgeWriteFailed('GitHub answered the write with something other than an issue.');
    }
}
