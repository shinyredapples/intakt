<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\UseCase\ScanCiChange;

use Intakt\Core\Git\Contract\RepositoryAttributesRefused;
use Intakt\Plugin\GitHubForge\Domain\CiScan;
use Intakt\Plugin\GitHubForge\Domain\CiScanResult;
use Intakt\Plugin\GitHubForge\Domain\CommitNotRead;
use Intakt\Plugin\GitHubForge\Infrastructure\MirrorWorkflows;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

// A command is the door because no push asks the scan yet; it shows the verdict the owner-approval step will hold the push on.
#[AsCommand(name: 'intakt:forge:ci-scan', description: 'Scan the head\'s, main\'s and the merged workflows of a repository and tell whether the head\'s CI change waits for the owner')]
final readonly class ScanCiChangeCommand
{
    public function __construct(
        private MirrorWorkflows $workflows,
        private CiScan $scan,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The git directory of the repository, a bare mirror or a .git folder')]
        string $repository,
        #[Argument('The main branch or commit')]
        string $main,
        #[Argument('The head branch or commit')]
        string $head,
    ): int {
        try {
            $result = $this->scan->of($this->workflows->of($repository, $main, $head));
        } catch (CommitNotRead|RepositoryAttributesRefused $failure) {
            $io->error($failure->getMessage());

            return Command::INVALID;
        }

        $this->show($io, $result);

        return $result->holdsThePush() ? Command::FAILURE : Command::SUCCESS;
    }

    private function show(SymfonyStyle $io, CiScanResult $result): void
    {
        foreach ($result->findings as $found) {
            $io->writeln(sprintf('%s %s line %d: %s: %s', $found->side->value, $this->printable($found->path), $found->finding->line, $found->finding->risk->value, $this->printable($found->finding->detail)));
        }

        foreach ($result->ciPaths as $path) {
            $io->writeln('ci '.$this->printable($path));
        }

        $io->writeln(sprintf('risky: %s', $result->isRisky() ? 'yes' : 'no'));
        $io->writeln(sprintf('CI change: %s', $result->isCiChange() ? 'yes' : 'no'));
        $io->writeln(sprintf('push waits for the owner: %s', $result->holdsThePush() ? 'yes' : 'no'));
    }

    private function printable(string $text): string
    {
        // Because a file name or a workflow's text may hold a newline or an escape sequence, which would forge a line of this listing or steer the terminal.
        return OutputFormatter::escape(addcslashes($text, "\0..\37\177\\"));
    }
}
