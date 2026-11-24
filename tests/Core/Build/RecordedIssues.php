<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Serves recorded issues to an install in the test environment, so the evidence of intakt:build:task needs no GitHub; GitHubIssueReads serves an installed instance.
/**
 * @phpstan-type Issue array{number: int, title: string, body: string, labels: list<array{name: string}>, user: array{login: string}, updated_at: string, milestone?: array{due_on: string|null}|null, pull_request?: array<mixed>}
 */
final readonly class RecordedIssues implements IssueReads
{
    public function __construct(
        #[Autowire('%env(BUILD_RECORDING)%')]
        private string $recording,
    ) {
    }

    public function listOpen(string $project, ?string $validator): IssueListing
    {
        return IssueListing::listed(array_map($this->listed(...), $this->read()), null);
    }

    public function readOpen(string $project, int $number): ?ListedIssue
    {
        $recorded = array_find($this->read(), static fn (array $issue): bool => $issue['number'] === $number && !isset($issue['pull_request']));

        return null === $recorded ? null : $this->listed($recorded);
    }

    /**
     * @param Issue $issue
     */
    private function listed(array $issue): ListedIssue
    {
        $due = $issue['milestone']['due_on'] ?? null;

        return new ListedIssue(
            $issue['number'],
            $issue['title'],
            $issue['body'],
            array_column($issue['labels'], 'name'),
            $issue['user']['login'],
            $this->moment($issue['updated_at']),
            null === $due ? null : $this->moment($due),
            isset($issue['pull_request']),
        );
    }

    private function moment(string $recorded): DateTimeImmutable
    {
        $moment = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $recorded, new DateTimeZone('UTC'));

        return false === $moment ? throw new LogicException('Not a recorded moment: '.$recorded) : $moment;
    }

    /**
     * @return list<Issue>
     */
    private function read(): array
    {
        // A recording of the form {"fails": "<message>"} stands for a listing GitHub did not answer in full, so a short listing can be shown on an install too.
        $file = is_file($this->recording) ? $this->recording : throw new LogicException('BUILD_RECORDING names no recording file.');
        /** @var list<Issue>|array{fails: string} $recorded */
        $recorded = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        return isset($recorded['fails']) ? throw new ForgeReadFailed($recorded['fails']) : $recorded;
    }
}
