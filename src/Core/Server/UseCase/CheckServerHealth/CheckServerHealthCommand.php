<?php

declare(strict_types=1);

namespace Intakt\Core\Server\UseCase\CheckServerHealth;

use Intakt\Core\Server\Contract\AdminApiNotOnLocalhost;
use Intakt\Core\Server\Contract\EndpointDown;
use Intakt\Core\Server\Contract\HubHealth;
use Intakt\Core\Server\Contract\ServerReading;
use Intakt\Core\Server\Contract\ServerStatus;
use Intakt\Core\Server\Contract\WorkerThreads;
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

        $problems = $this->problems($reading);
        foreach ($problems as $problem) {
            $io->writeln('unhealthy: '.$problem);
        }
        if ([] !== $problems) {
            return Command::FAILURE;
        }
        $io->writeln('healthy');

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function problems(ServerReading $reading): array
    {
        return array_values(array_filter([
            $this->threadsProblem($reading->threads),
            $reading->metrics instanceof EndpointDown ? 'server metrics down ('.$reading->metrics->reason.')' : null,
            $this->hubProblem($reading->hub),
        ], is_string(...)));
    }

    private function threadsProblem(WorkerThreads|EndpointDown $threads): ?string
    {
        if ($threads instanceof EndpointDown) {
            return 'worker threads down ('.$threads->reason.')';
        }

        // A busy thread counts as ready, because it takes the next request once it is done; only threads that are all booting or restarting can take none.
        return 0 === $threads->busy + $threads->idle ? 'no worker thread is ready' : null;
    }

    private function hubProblem(HubHealth|EndpointDown $hub): ?string
    {
        if ($hub instanceof EndpointDown) {
            return 'hub down ('.$hub->reason.')';
        }

        return HubHealth::Unhealthy === $hub ? 'the hub reports unhealthy' : null;
    }
}
