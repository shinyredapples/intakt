<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Symfony\Component\Process\Process;

// `gh` takes its token from GH_TOKEN in the environment, so no credential passes through this class.
final readonly class GitHubIssues
{
    private const string REPOSITORY = '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/';
    private const int SECONDS = 60;
    private const int OPEN_ISSUES_READ = 1000;

    public function __construct(private string $repository)
    {
    }

    public function oldestOpenIssueHolding(string $marker): ?int
    {
        $marked = array_filter(
            $this->openIssues(),
            static fn (array $issue): bool => in_array($marker, explode("\n", str_replace("\r", '', $issue['body'])), true),
        );

        return [] === $marked ? null : min(array_column($marked, 'number'));
    }

    /**
     * @param list<string> $labels
     */
    public function create(string $title, string $body, array $labels): string
    {
        $labelArguments = array_merge(...array_map(static fn (string $label): array => ['--label', $label], $labels));

        return $this->gh(['issue', 'create', '--title', $title, ...$labelArguments, '--body-file', '-'], $body);
    }

    public function replaceBody(int $number, string $body): string
    {
        return $this->gh(['issue', 'edit', (string) $number, '--body-file', '-'], $body);
    }

    /**
     * @return list<array{number: int, body: string}>
     */
    private function openIssues(): array
    {
        $answer = $this->gh(['issue', 'list', '--state', 'open', '--limit', (string) self::OPEN_ISSUES_READ, '--json', 'number,body'], '');
        $issues = json_validate($answer) ? json_decode($answer, true) : null;

        if (!is_array($issues) || !array_is_list($issues) || !array_all($issues, $this->isIssue(...))) {
            throw new GitHubCallFailed('gh issue list answered something that is not a list of issues with a number and a body');
        }

        /* @var list<array{number: int, body: string}> $issues */
        return $issues;
    }

    private function isIssue(mixed $issue): bool
    {
        return is_array($issue) && is_int($issue['number'] ?? null) && is_string($issue['body'] ?? null);
    }

    /**
     * @param list<string> $arguments
     */
    private function gh(array $arguments, string $input): string
    {
        if (1 !== preg_match(self::REPOSITORY, $this->repository)) {
            throw new GitHubCallFailed('GITHUB_REPOSITORY must name the repository as <owner>/<name>');
        }

        $process = new Process(['gh', ...$arguments, '--repo', $this->repository], null, null, $input, self::SECONDS);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new GitHubCallFailed('gh '.$arguments[0].' '.$arguments[1].' failed: '.trim($process->getErrorOutput()));
        }

        return trim($process->getOutput());
    }
}
