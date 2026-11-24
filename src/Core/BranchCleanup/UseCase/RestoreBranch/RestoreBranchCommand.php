<?php

declare(strict_types=1);

namespace Intakt\Core\BranchCleanup\UseCase\RestoreBranch;

use Intakt\Core\Platform\Contract\ReportableFailure;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:branches:restore', description: 'Restore a branch that branch cleanup deleted, from its newest archive ref in the project mirror')]
final readonly class RestoreBranchCommand
{
    public function __construct(
        private RestoreBranch $restore,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The project name')]
        string $project,
        #[Argument(description: 'The name of the deleted branch')]
        string $branch,
    ): int {
        try {
            $archived = $this->restore->restore($project, $branch);
        } catch (ReportableFailure $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->writeln(sprintf('restored %s at %s from %s', $archived->branch, $archived->commit, $archived->ref));

        return Command::SUCCESS;
    }
}
