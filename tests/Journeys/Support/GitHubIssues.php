<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use Closure;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

// `gh` takes its token from GH_TOKEN or GITHUB_TOKEN in the environment, so no credential passes through this class.
final readonly class GitHubIssues
{
    private const string REPOSITORY = '/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/';
    private const string ISSUE_LINK = '/\/issues\/(?<number>[1-9][0-9]*)$/';
    private const array TOKENS = ['GH_TOKEN', 'GITHUB_TOKEN'];
    private const int SECONDS = 60;
    private const string LOGIN = '/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,38})(?:\[bot\])?$/';
    private const string INTEGRATION_REFUSAL = 'Resource not accessible by integration';
    private const string ACTIONS_BOT = 'github-actions[bot]';

    /**
     * @param (Closure(list<string>, string): string)|null $runner
     */
    public function __construct(private string $repository, private ?Closure $runner = null)
    {
    }

    // An author narrows the search to issues that account opened, because anyone can copy a marker into an issue of their own.
    public function oldestOpenIssueHolding(string $marker, ?string $author = null): ?int
    {
        $marked = array_filter(
            $this->openIssues(),
            static fn (array $issue): bool => (null === $author || $author === $issue['author'])
                && in_array($marker, explode("\n", str_replace("\r", '', $issue['body'])), true),
        );

        return [] === $marked ? null : min(array_column($marked, 'number'));
    }

    // Only a body whose whole first line matches counts, because the alarm writes its marker there, and an issue that quotes a marker further down, or an example of one, is not the alarm's to close.
    /**
     * @return array<int, string>
     */
    public function openIssuesOpeningWith(string $pattern, string $author): array
    {
        $marked = [];

        foreach ($this->openIssues() as $issue) {
            $first = explode("\n", str_replace("\r", '', $issue['body']), 2)[0];

            if ($author === $issue['author'] && 1 === preg_match($pattern, $first)) {
                $marked[$issue['number']] = $first;
            }
        }

        return $marked;
    }

    /**
     * @param list<string> $labels
     *
     * @return array{number: int, link: string}
     */
    public function create(string $title, string $body, array $labels): array
    {
        $labelArguments = array_merge(...array_map(static fn (string $label): array => ['--label', $label], $labels));
        $link = $this->issueCommand(['create', '--title', $title, ...$labelArguments, '--body-file', '-'], $body);

        if (1 !== preg_match(self::ISSUE_LINK, $link, $match)) {
            throw new GitHubCallFailed('gh issue create answered something that is not a link to an issue');
        }

        return ['number' => (int) $match['number'], 'link' => $link];
    }

    public function replaceBody(int $number, string $body): void
    {
        $this->issueCommand(['edit', (string) $number, '--body-file', '-'], $body);
    }

    public function closeAsDuplicateOf(int $number, int $original): void
    {
        $this->issueCommand(['close', (string) $number, '--comment', 'Duplicate of #'.$original], '');
    }

    public function close(int $number, string $comment): void
    {
        $this->issueCommand(['close', (string) $number, '--comment', $comment], '');
    }

    // A workflow's own token may not read its account, and such a token always files as the Actions bot.
    public function account(): string
    {
        try {
            $login = $this->gh(['api', 'user', '--jq', '.login'], '');
        } catch (GitHubCallFailed $refused) {
            $login = str_contains($refused->getMessage(), self::INTEGRATION_REFUSAL) ? self::ACTIONS_BOT : throw $refused;
        }

        return 1 === preg_match(self::LOGIN, $login) ? $login : throw new GitHubCallFailed('gh api user answered something that is not an account login');
    }

    /**
     * @return list<array{number: int, body: string, author: ?string}>
     */
    private function openIssues(): array
    {
        // Every page is read, so a marked task behind any number of newer issues is still found.
        $answer = $this->gh(['api', 'repos/'.$this->repository.'/issues?state=open&per_page=100', '--paginate', '--slurp'], '');
        $pages = json_validate($answer) ? json_decode($answer, true) : null;
        $items = array_merge([], ...array_map($this->pageFrom(...), is_array($pages) && array_is_list($pages) ? $pages : throw $this->notAnIssueList()));
        $issues = array_filter($items, static fn (mixed $item): bool => !is_array($item) || !array_key_exists('pull_request', $item));

        return array_values(array_map($this->issueFrom(...), $issues));
    }

    /**
     * @param list<string> $arguments
     */
    private function issueCommand(array $arguments, string $input): string
    {
        return $this->gh(['issue', ...$arguments, '--repo', $this->repository], $input);
    }

    /**
     * @param list<string> $arguments
     */
    private function gh(array $arguments, string $input): string
    {
        if (1 !== preg_match(self::REPOSITORY, $this->repository)) {
            throw new GitHubCallFailed('GITHUB_REPOSITORY must name the repository as <owner>/<name>');
        }

        if (array_all(self::TOKENS, static fn (string $name): bool => '' === (string) getenv($name))) {
            throw new GitHubCallFailed('GH_TOKEN or GITHUB_TOKEN must hold the token, so gh never falls back to a stored login');
        }

        return ($this->runner ?? $this->runProcess(...))($arguments, $input);
    }

    /**
     * @return list<mixed>
     */
    private function pageFrom(mixed $page): array
    {
        return is_array($page) && array_is_list($page) ? $page : throw $this->notAnIssueList();
    }

    private function notAnIssueList(): GitHubCallFailed
    {
        return new GitHubCallFailed('gh api answered something that is not a list of issues with a number and a body');
    }

    /**
     * @return array{number: int, body: string, author: ?string}
     */
    private function issueFrom(mixed $issue): array
    {
        $number = is_array($issue) ? $issue['number'] ?? null : null;
        $hasBody = is_array($issue) && array_key_exists('body', $issue);
        $body = $hasBody ? $issue['body'] : null;

        if (!is_int($number) || !$hasBody || (null !== $body && !is_string($body))) {
            throw $this->notAnIssueList();
        }

        // An issue without a readable author belongs to no account, so no author-bound search finds it.
        $user = $issue['user'] ?? null;
        $author = is_array($user) && is_string($user['login'] ?? null) ? $user['login'] : null;

        return ['number' => $number, 'body' => $body ?? '', 'author' => $author];
    }

    /**
     * @param list<string> $arguments
     */
    private function runProcess(array $arguments, string $input): string
    {
        $process = new Process(['gh', ...$arguments], null, null, $input, self::SECONDS);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new GitHubCallFailed('gh '.$arguments[0].' '.$arguments[1].' timed out after '.self::SECONDS.' seconds');
        }

        if (!$process->isSuccessful()) {
            throw new GitHubCallFailed('gh '.$arguments[0].' '.$arguments[1].' failed: '.trim($process->getErrorOutput()));
        }

        return trim($process->getOutput());
    }
}
