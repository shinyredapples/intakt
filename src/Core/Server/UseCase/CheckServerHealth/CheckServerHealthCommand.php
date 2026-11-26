<?php

declare(strict_types=1);

namespace Intakt\Core\Server\UseCase\CheckServerHealth;

use Intakt\Core\Server\Contract\AdminApiNotOnLocalhost;
use Intakt\Core\Server\Contract\InfrastructureChecks;
use Intakt\Core\Server\Contract\ServerStatus;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

// A command is the door because the container's health check (docker/healthcheck.sh) runs it inside the container, where the admin API answers.
#[AsCommand(name: 'intakt:server:health', description: 'Exit non-zero when the Mercure hub is unhealthy, no worker thread is ready or an admin API endpoint is down')]
final readonly class CheckServerHealthCommand
{
    public function __construct(
        private ServerStatus $status,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $reading = $this->status->read();
        } catch (AdminApiNotOnLocalhost $refused) {
            $io->writeln('unhealthy: '.$refused->getMessage());

            return Command::FAILURE;
        }

        $problems = InfrastructureChecks::of($reading)->problems;
        foreach ($problems as $problem) {
            $io->writeln('unhealthy: '.$problem);
        }
        if ([] !== $problems) {
            return Command::FAILURE;
        }
        $io->writeln('healthy');

        return Command::SUCCESS;
    }
}
