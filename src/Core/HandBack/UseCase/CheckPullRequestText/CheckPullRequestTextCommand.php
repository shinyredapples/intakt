<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\CheckPullRequestText;

use Intakt\Core\HandBack\Contract\PullRequestTextOutcome;
use Intakt\Core\HandBack\Domain\PullRequestTextRule;
use Intakt\Core\Platform\Contract\ReportableFailure;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommand(name: 'intakt:check:pull-request-text', description: 'Check that a build\'s pull request text references every top-level Evidence item of the task and that its body stays under the cap; a miss is sent back once, a second in a row goes to the reviewers')]
final readonly class CheckPullRequestTextCommand
{
    public function __construct(
        private Filesystem $filesystem,
        private RoutePullRequestText $round,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] CheckPullRequestTextInput $input): int
    {
        try {
            $task = PullRequestTextTask::resolved($input->repository, $input->branch);
            $result = $this->round->route($task, $this->read($input->task), $this->bodyOf($this->read($input->pullRequestText)));
        } catch (InvalidArgumentException|ReportableFailure $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        return $result instanceof FailedPullRequestText ? $this->failed($io, $result) : $this->passed($io, $result);
    }

    private function read(string $path): string
    {
        try {
            return $this->filesystem->readFile($path);
        } catch (IOException) {
            throw new InvalidArgumentException(sprintf('the file "%s" cannot be read', $path));
        }
    }

    // Trimmed after the title line as the build step trims it before the push, so the cap counts the body the forge would get.
    private function bodyOf(string $pullRequestText): string
    {
        return trim(explode("\n", $pullRequestText, 2)[1] ?? '');
    }

    private function failed(SymfonyStyle $io, FailedPullRequestText $failed): int
    {
        $outcome = $failed->outcome;
        $route = match ($failed->route) {
            PullRequestTextRoute::SentBack => 'sent back to the builder',
            PullRequestTextRoute::ToReviewers => 'handed to the reviewers',
        };
        $io->error(sprintf('failed, %s: %s', $route, $outcome->verdict->reason()));

        if ([] !== $outcome->missingItems) {
            $io->writeln('missing: '.implode(', ', array_map(static fn (int $item): string => 'E'.$item, $outcome->missingItems)));
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
