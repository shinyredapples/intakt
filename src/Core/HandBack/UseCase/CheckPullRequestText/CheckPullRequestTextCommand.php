<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckPullRequestText;

use Intakt\Core\HandBack\Contract\FailedPullRequestText;
use Intakt\Core\HandBack\Contract\PullRequestTextOutcome;
use Intakt\Core\HandBack\Contract\PullRequestTextRound;
use Intakt\Core\HandBack\Contract\PullRequestTextRoute;
use Intakt\Core\HandBack\Contract\PullRequestTextTask;
use Intakt\Core\HandBack\Domain\PullRequestTextRule;
use Intakt\Core\Platform\Contract\ReportableFailure;
use Intakt\Core\HandBack\Contract\PullRequestTextTaskRefused;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'intakt:check:pull-request-text', description: 'Check that a build\'s pull request text references every top-level Evidence item of the task and that its body stays under the cap; with a repository and a branch, run the build\'s round, which sends a miss back once and a second in a row to the reviewers')]
final readonly class CheckPullRequestTextCommand
{
    public function __construct(
        private Filesystem $filesystem,
        private PullRequestTextRound $round,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] CheckPullRequestTextInput $input): int
    {
        try {
            $result = $this->resultOf($input, $this->read($input->task), $this->bodyOf($this->read($input->pullRequestText)));
        } catch (PullRequestTextFileUnreadable|PullRequestTextTaskRefused|ReportableFailure $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        return match (true) {
            $result instanceof FailedPullRequestText => $this->failed($io, $result->outcome, $this->wordsOf($result->route)),
            $result->passed() => $this->passed($io, $result),
            default => $this->failed($io, $result, null),
        };
    }

    private function read(string $path): string
    {
        try {
            return $this->filesystem->readFile($path);
        } catch (IOException) {
            throw new PullRequestTextFileUnreadable(sprintf('the file "%s" cannot be read', $path));
        }
    }

    // Trimmed after the title line as the build step trims it before the push, so the cap counts the body the forge would get.
    private function bodyOf(string $pullRequestText): string
    {
        return trim(explode("\n", $pullRequestText, 2)[1] ?? '');
    }

    // Recorded only when a repository names the task, because a person's own check must not use up the go-back of the build's next miss.
    private function resultOf(CheckPullRequestTextInput $input, string $task, string $body): PullRequestTextOutcome|FailedPullRequestText
    {
        if (null === $input->repository) {
            return PullRequestTextRule::judge($task, $body);
        }

        return $this->round->route(PullRequestTextTask::resolved($input->repository, $input->branch ?? ''), $task, $body);
    }

    private function wordsOf(PullRequestTextRoute $route): string
    {
        return match ($route) {
            PullRequestTextRoute::SentBack => 'sent back to the builder',
            PullRequestTextRoute::ToReviewers => 'handed to the reviewers',
        };
    }

    private function failed(SymfonyStyle $io, PullRequestTextOutcome $outcome, ?string $route): int
    {
        $io->error(sprintf('failed%s: %s', null === $route ? '' : ', '.$route, $outcome->verdict->reason()));

        if (!$outcome->missingItems->isEmpty()) {
            $io->writeln('missing: '.$outcome->missingItems->references());
        }

        $io->writeln(sprintf('body: %d characters, cap %d', $outcome->bodyCharacters, PullRequestTextRule::MAX_BODY_CHARACTERS));

        return Command::FAILURE;
    }

    private function passed(SymfonyStyle $io, PullRequestTextOutcome $outcome): int
    {
        $io->writeln(sprintf('passed: %d Evidence items referenced, body of %d characters (cap %d)', $outcome->evidenceItems, $outcome->bodyCharacters, PullRequestTextRule::MAX_BODY_CHARACTERS));

        return Command::SUCCESS;
    }
}
