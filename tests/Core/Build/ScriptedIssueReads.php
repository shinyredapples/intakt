<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build;

use DateTimeImmutable;
use Intakt\Plugin\GitHubForge\Contract\IssueListing;
use Intakt\Plugin\GitHubForge\Contract\IssueReads;
use Intakt\Plugin\GitHubForge\Contract\ListedIssue;
use Override;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

// The listing of an install in the test environment, so intakt:build:task runs on it while no forge answers: one open issue per number named in the variable.
final readonly class ScriptedIssueReads implements IssueReads
{
    public function __construct(
        #[Autowire(env: 'BUILD_ISSUES')]
        private string $numbers,
    ) {
    }

    #[Override]
    public function listOpen(string $project, ?string $validator): IssueListing
    {
        $issues = array_map(
            static fn (string $number): ListedIssue => new ListedIssue((int) $number, 'Scripted issue '.$number, 'Scripted body', [], 'scripted', new DateTimeImmutable('2026-01-01T00:00:00Z'), null, false),
            array_filter(explode(',', $this->numbers), static fn (string $number): bool => '' !== $number),
        );

        return IssueListing::listed(array_values($issues), null);
    }
}
