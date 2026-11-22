<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\UseCase\ApprovePush;

use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Plugin\Forge\Contract\ForgeRateLimited;
use Intakt\Plugin\Forge\Contract\ForgeReadFailed;
use Intakt\Plugin\Forge\Contract\ForgeWriteFailed;
use Intakt\Plugin\GitHubForge\Domain\ChangeNotShown;
use Intakt\Plugin\GitHubForge\Domain\CommitNotRead;
use Intakt\Plugin\GitHubForge\Domain\TaskIssue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\MapInput;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

// A command is the door because no lane pushes yet; its exit code is the answer the push step (plan T53) will hold the push on.
#[AsCommand(name: 'intakt:forge:push-approval', description: 'Tell whether a head may be pushed, and ask the owner on the issue with its complete diff when its CI change is risky')]
final readonly class PushApprovalCommand
{
    public function __construct(
        private PushGate $gate,
    ) {
    }

    public function __invoke(SymfonyStyle $io, #[MapInput] PushApprovalInput $input): int
    {
        $issue = new TaskIssue($input->project, $input->issue);

        try {
            $outcome = $this->gate->decide($input->repository, $input->head, $issue);
        } catch (ChangeNotShown $notShown) {
            $io->writeln($this->printable('push: held, the change cannot be shown in full and goes back to be split: '.$notShown->getMessage()));

            return Command::FAILURE;
        } catch (CommitNotRead|RepositoryAttributesRefused|ForgeReadFailed|ForgeWriteFailed|ForgeRateLimited $failure) {
            $io->writeln($this->printable('push: held, nothing was decided: '.$failure->getMessage()));

            return Command::INVALID;
        }

        $this->show($io, $outcome, $issue);

        return $outcome->decision->letsItPush() ? Command::SUCCESS : Command::FAILURE;
    }

    private function printable(string $text): string
    {
        // Because a file name or a workflow's text may hold a newline or an escape sequence, which would forge a line of this listing or steer the terminal.
        return OutputFormatter::escape(addcslashes($text, "\0..\37\177\\"));
    }

    private function show(SymfonyStyle $io, PushOutcome $outcome, TaskIssue $issue): void
    {
        foreach ($outcome->scan->findings as $found) {
            $io->writeln($this->printable(sprintf('%s %s line %d: %s: %s', $found->side->value, $found->path, $found->finding->line, $found->finding->risk->value, $found->finding->detail)));
        }

        foreach ($outcome->scan->ciPaths as $path) {
            $io->writeln($this->printable('ci '.$path));
        }

        $io->writeln(match ($outcome->decision) {
            PushDecision::Free => 'push: free (no risky CI change)',
            PushDecision::Approved => sprintf('push: approved by the owner for %s', $outcome->commit),
            PushDecision::Asked => sprintf('push: held, the owner was asked on issue #%d to approve %s', $issue->number, $outcome->commit),
            PushDecision::StillAsked => sprintf('push: held, still waiting for the owner\'s approve %s on issue #%d', $outcome->commit, $issue->number),
        });
    }
}
