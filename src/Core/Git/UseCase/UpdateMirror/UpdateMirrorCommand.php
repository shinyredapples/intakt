<?php

declare(strict_types=1);

namespace Intakt\Core\Git\UseCase\UpdateMirror;

use Intakt\Core\Platform\Contract\ReportableFailure;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'intakt:mirror:update', description: 'Create or update the bare mirror of a project from its remote, retrying a failed read after 10 and 30 seconds')]
final readonly class UpdateMirrorCommand
{
    public function __construct(
        private UpdateMirror $mirror,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'The project name')]
        string $project,
        #[Argument(description: 'The remote to mirror: a path, a file:// or an https:// URL')]
        string $remote,
    ): int {
        try {
            $path = $this->mirror->update($project, $remote);
        } catch (ReportableFailure $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->writeln('mirror '.$path);

        return Command::SUCCESS;
    }
}
