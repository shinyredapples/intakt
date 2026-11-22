<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build;

use DateTimeImmutable;
use DateTimeZone;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// Serves recorded issues to an install in the test environment, so the evidence of intakt:build:task needs no GitHub; GitHubIssueReads serves an installed instance.
/**
 * @phpstan-type Issue array{number: int, title: string, body: string, labels: list<array{name: string}>, user: array{login: string}, updated_at: string, pull_request?: array<mixed>}
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
        $moment = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', $issue['updated_at'], new DateTimeZone('UTC'));

        return new ListedIssue(
            $issue['number'],
            $issue['title'],
            $issue['body'],
            array_column($issue['labels'], 'name'),
            $issue['user']['login'],
            false === $moment ? throw new LogicException('Not a recorded moment: '.$issue['updated_at']) : $moment,
            null,
            isset($issue['pull_request']),
        );
    }

    /**
     * @return list<Issue>
     */
    private function read(): array
    {
        $file = is_file($this->recording) ? $this->recording : throw new LogicException('BUILD_RECORDING names no recording file.');
        /** @var list<Issue> $recorded */
        $recorded = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        return $recorded;
    }
}
