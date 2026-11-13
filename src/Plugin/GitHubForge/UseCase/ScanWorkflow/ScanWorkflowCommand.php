<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\UseCase\ScanWorkflow;

use Intakt\Plugin\GitHubForge\Domain\WorkflowFileScan;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

// A command is the door because no push reads the workflows yet; it shows the findings that push will get.
#[AsCommand(name: 'intakt:forge:workflow-risk', description: 'Scan a GitHub Actions workflow file for anything beyond a hosted runner with a read-only token and no secrets')]
final readonly class ScanWorkflowCommand
{
    public function __construct(
        private WorkflowFileScan $scan,
        private Filesystem $filesystem,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The workflow file to scan')]
        string $file,
    ): int {
        try {
            $workflow = $this->filesystem->readFile($file);
        } catch (IOException $failure) {
            $io->error($failure->getMessage());

            return Command::INVALID;
        }

        $findings = $this->scan->scan($workflow);
        foreach ($findings as $finding) {
            $io->writeln(sprintf('line %d: %s: %s', $finding->line, $finding->risk->value, $finding->detail));
        }

        $io->writeln($findings === [] ? 'no risk: hosted runners, a read-only token, no secrets' : sprintf('risky: %d findings', count($findings)));

        return $findings === [] ? Command::SUCCESS : Command::FAILURE;
    }
}
