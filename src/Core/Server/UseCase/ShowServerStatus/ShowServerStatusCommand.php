<?php

declare(strict_types=1);

namespace Intakt\Core\Server\UseCase\ShowServerStatus;

use Intakt\Core\Platform\Contract\ReportableFailure;
use Intakt\Core\Server\Contract\EndpointDown;
use Intakt\Core\Server\Contract\HubHealth;
use Intakt\Core\Server\Contract\ServerMetrics;
use Intakt\Core\Server\Contract\ServerReading;
use Intakt\Core\Server\Contract\ServerStatus;
use Intakt\Core\Server\Contract\WorkerThreads;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

// A command is the door because the dashboard row and the health check (later parts of plan task T34) are not built yet.
#[AsCommand(name: 'intakt:server:status', description: 'Read worker threads, queue depth, worker restarts and hub health from the FrankenPHP admin API')]
final readonly class ShowServerStatusCommand
{
    public function __construct(
        private ServerStatus $status,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $reading = $this->status->read();
        } catch (ReportableFailure $failure) {
            $io->error($failure->getMessage());

            return Command::FAILURE;
        }

        $io->writeln('worker threads: '.$this->workerThreads($reading->threads));
        $io->writeln('queue depth: '.$this->metric($reading->metrics, static fn (ServerMetrics $metrics): int => $metrics->queueDepth));
        $io->writeln('worker restarts: '.$this->metric($reading->metrics, static fn (ServerMetrics $metrics): int => $metrics->workerRestarts));
        $io->writeln('hub: '.($reading->hub instanceof HubHealth ? $reading->hub->value : $this->down($reading->hub)));

        return $this->anyDown($reading) ? Command::FAILURE : Command::SUCCESS;
    }

    private function workerThreads(WorkerThreads|EndpointDown $threads): string
    {
        return $threads instanceof WorkerThreads ? sprintf('%d busy, %d idle', $threads->busy, $threads->idle) : $this->down($threads);
    }

    /**
     * @param callable(ServerMetrics): int $value
     */
    private function metric(ServerMetrics|EndpointDown $metrics, callable $value): string
    {
        return $metrics instanceof ServerMetrics ? (string) $value($metrics) : $this->down($metrics);
    }

    private function down(EndpointDown $down): string
    {
        return 'down ('.$down->reason.')';
    }

    private function anyDown(ServerReading $reading): bool
    {
        return $reading->threads instanceof EndpointDown || $reading->metrics instanceof EndpointDown || $reading->hub instanceof EndpointDown;
    }
}
