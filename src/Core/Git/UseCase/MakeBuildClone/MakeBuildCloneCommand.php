<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\MakeBuildClone;

use Intakt\Core\Platform\Contract\ReportableFailure;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:build:clone', description: 'Clone main and the task\'s branch, and no other ref, from the project\'s bare mirror into a new folder')]
final readonly class MakeBuildCloneCommand
{
    public function __construct(
        private MakeBuildClone $clones,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The project name')]
        string $project,
        #[Argument(description: 'The task\'s branch; one the mirror does not hold yet starts at main')]
        string $branch,
        #[Argument(description: 'The folder to clone into, which must not exist yet')]
        string $directory,
    ): int {
        try {
            $this->clones->make($project, $branch, $directory);
        } catch (InvalidArgumentException|ReportableFailure $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->writeln('clone '.$directory);

        return Command::SUCCESS;
    }
}
