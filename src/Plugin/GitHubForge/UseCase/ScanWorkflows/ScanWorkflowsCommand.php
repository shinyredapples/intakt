<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\UseCase\ScanWorkflows;

use Intakt\Plugin\GitHubForge\Domain\CiDefinition;
use Intakt\Plugin\GitHubForge\Domain\WorkflowFile;
use Intakt\Plugin\GitHubForge\Domain\WorkflowRisk;
use Intakt\Plugin\GitHubForge\Domain\WorkflowRiskScan;
use Intakt\Plugin\GitHubForge\Infrastructure\WorkflowFiles;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

// A command is the door because no build holds its push yet; it shows the findings that hold will act on.
#[AsCommand(name: 'intakt:forge:workflow-risk', description: 'Scan the GitHub workflows of a checkout for CI risk')]
final readonly class ScanWorkflowsCommand
{
    public function __construct(
        private WorkflowFiles $files,
        private WorkflowRiskScan $scan,
        private CiDefinition $ciDefinition,
    ) {
    }

    /** @param list<string> $changed */
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('The checkout whose .github/workflows/ to scan')]
        string $checkout,
        #[Option('A changed path, relative to the checkout, to check for a CI change; repeatable')]
        array $changed = [],
    ): int {
        if (!is_dir($checkout)) {
            $io->error(sprintf('%s is not a directory', $checkout));

            return Command::FAILURE;
        }

        $workflows = $this->files->in($checkout);
        $this->showRisk($io, $this->scan->scan(...$workflows), count($workflows));
        if ($changed !== []) {
            $this->showCiChanges($io, $workflows, $changed);
        }

        return Command::SUCCESS;
    }

    private function showRisk(SymfonyStyle $io, WorkflowRisk $risk, int $workflows): void
    {
        foreach ($risk->findings as $finding) {
            $io->writeln(sprintf('%s:%d %s: %s', $finding->path, $finding->line, $finding->kind->value, $finding->detail));
        }
        $io->writeln($risk->isRisky()
            ? sprintf('Risky: %d findings in %d workflows', count($risk->findings), $workflows)
            : sprintf('Not risky: %d workflows, every job on a GitHub-hosted runner with a read-only token and no secrets', $workflows));
    }

    /**
     * @param list<WorkflowFile> $workflows
     * @param list<string>       $changed
     */
    private function showCiChanges(SymfonyStyle $io, array $workflows, array $changed): void
    {
        $ciPaths = $this->ciDefinition->changedAmong($workflows, $changed);
        foreach ($ciPaths as $path) {
            $io->writeln(sprintf('CI change: %s', $path));
        }
        if ($ciPaths === []) {
            $io->writeln(sprintf('No CI change among %d changed paths', count($changed)));
        }
    }
}
