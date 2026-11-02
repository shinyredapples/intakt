<?php

declare(strict_types=1);

namespace Intakt\Plugin\GitHubForge\UseCase\CheckInstallation;

use Intakt\Core\Platform\Contract\ReportableFailure;
use Intakt\Plugin\GitHubForge\Infrastructure\InstallationReadBack;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

// The start door: whatever starts Intakt runs this first and stops on a non-zero exit.
#[AsCommand(name: 'intakt:forge:installation', description: 'Read the GitHub App installation back and refuse a permission or repository mismatch')]
final readonly class CheckInstallationCommand
{
    public function __construct(
        private InstallationReadBack $readBack,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $installation = $this->readBack->check();
        } catch (ReportableFailure $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        foreach ($installation->permissions as $name => $level) {
            $io->writeln(sprintf('permission %s:%s', $name, $level));
        }
        foreach ($installation->repositories as $repository) {
            $io->writeln('repository '.$repository);
        }
        $io->success('The installation matches the configuration.');

        return Command::SUCCESS;
    }
}
