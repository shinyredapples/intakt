<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\Infrastructure;

use DateTimeZone;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Contract\PartIssue;
use Intakt\Plugin\GitHubForge\Contract\TaskParts;
use JsonException;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsAlias(TaskParts::class)]
final readonly class GitHubTaskParts implements TaskParts
{
    private const int PAGE_SIZE = 100;
    // A search of more pages than this fails, because a part filed past them would be filed a second time.
    private const int MAX_PAGES = 10;
    // Searched from a day before the plan, because GitHub's clock and this host's may differ, and a part filed by GitHub's earlier clock must still be found.
    private const string CLOCK_MARGIN = '-1 day';

    public function __construct(
        private PartIssueCalls $calls,
        private WatchedRepositories $repositories,
        private CreatedParts $created,
        #[Autowire(env: 'GITHUB_APP_ID')]
        private string $appId,
    ) {
    }

    #[Override]
    public function fileOnce(string $project, PartIssue $part): int
    {
        $place = new PartPlace($project, $this->repositories->addressOf($project)->path());

        // Held across the lookup, the create and the record, because two overlapping runs could both find nothing and both file the part.
        return $this->created->whileHeld($place, $part, fn (): int => $this->filedOnce($place, $part));
    }

    /**
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     */
    private function filedOnce(PartPlace $place, PartIssue $part): int
    {
        $path = $place->repository.'/issues';
        $kept = $this->created->numberOf($place, $part);

        if (null !== $kept) {
            return $this->reconciled($path.'/'.$kept, $kept, $part);
        }

        $found = $this->filedBefore($path, $part);

        if (null !== $found) {
            return $found;
        }

        // An earlier create whose number was never kept may have stored the part without the marker, which no search finds, so a person checks the issues instead of this run filing it again.
        if ($this->created->attempted($place, $part)) {
            throw new ForgeWriteFailed('An earlier create of this part kept no issue number and no issue carries its marker, so nothing was filed again; check the repository\'s issues for the part.');
        }

        return $this->filed($path, $place, $part);
    }

    // Without this App's mark, because the number is the answer to this App's own create, and the mark may be what that answer lacked; written only when the marker is missing, because the create may have stored it and only the answer lost it.
    /**
     * @throws ForgeReadFailed
     * @throws ForgeWriteFailed
     */
    private function reconciled(string $url, int $kept, PartIssue $part): int
    {
        $stored = $this->storedBodyOf($url, $kept);

        if (str_contains($stored, $part->marker)) {
            return $kept;
        }

        $answer = $this->calls->written('PATCH', $url, ['body' => ('' === trim($stored) ? $part->body : $stored)."\n\n".$part->marker]);
        $written = $answer['body'] ?? null;

        return is_string($written) && str_contains($written, $part->marker) ? $kept : throw new ForgeWriteFailed(sprintf('GitHub did not keep the marker written into part #%d.', $kept));
    }

    /**
     * @throws ForgeReadFailed
     */
    private function storedBodyOf(string $url, int $kept): string
    {
        $answer = $this->calls->read($url, [])->body;

        try {
            $issue = json_decode($answer, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $invalid) {
            throw new ForgeReadFailed('GitHub answered the read of an issue with something other than JSON.', $invalid->getCode(), previous: $invalid);
        }

        if (!is_array($issue) || !str_starts_with(ltrim($answer), '{') || $this->numberOf($issue) !== $kept || array_key_exists('pull_request', $issue)) {
            throw new ForgeReadFailed(sprintf('GitHub answered the read of part #%d with something other than that issue.', $kept));
        }

        $body = $issue['body'] ?? null;

        return is_string($body) ? $body : '';
    }

    /**
     * @param array<mixed> $issue
     */
    private function numberOf(array $issue): ?int
    {
        $number = $issue['number'] ?? null;

        return is_int($number) && $number > 0 ? $number : null;
    }

    // By this App's mark and the marker, never by the title, because anyone can write the marker into an issue of their own, and two parts may share a title.
    /**
     * @throws ForgeReadFailed
     */
    private function filedBefore(string $path, PartIssue $part): ?int
    {
        $since = $part->plannedAt->modify(self::CLOCK_MARGIN)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

        for ($page = 1; $page <= self::MAX_PAGES; ++$page) {
            $answer = $this->calls->read($path, ['state' => 'all', 'since' => $since, 'sort' => 'created', 'direction' => 'asc', 'per_page' => self::PAGE_SIZE, 'page' => $page]);
            $listed = $this->listIn($answer->body);
            $found = array_find($listed, fn (array $issue): bool => $this->isThisAppsPart($issue, $part->marker));

            if (null !== $found) {
                return $this->numberOf($found) ?? throw new ForgeReadFailed('GitHub listed a part without its number.');
            }

            // The Link header's word, not the page's length, ends the search, because GitHub may serve a shorter page than asked for and still have more.
            if (!$answer->hasNextPage && count($listed) < self::PAGE_SIZE) {
                return null;
            }
        }

        throw new ForgeReadFailed('GitHub lists more issues since the split was planned than this forge reads.');
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

    // Kept before the answer is checked for the marker, because GitHub may have stored a part whose answer lacks it, and the next run must check that issue instead of filing the part again.
    /**
     * @throws ForgeWriteFailed
     */
    private function filed(string $path, PartPlace $place, PartIssue $part): int
    {
        $this->created->intend($place, $part);
        $issue = $this->calls->written('POST', $path, ['title' => $part->title, 'body' => $part->body."\n\n".$part->marker, 'labels' => [...$part->labels]]);
        $number = $this->numberOf($issue) ?? throw new ForgeWriteFailed('GitHub answered the create without the number of the issue.');
        $this->created->keep($place, $part, $number);

        return $this->isThisAppsPart($issue, $part->marker) ? $number : throw new ForgeWriteFailed(sprintf('GitHub answered the create of part #%d without this App\'s mark or its marker, so the next run checks that issue.', $number));
    }
}
