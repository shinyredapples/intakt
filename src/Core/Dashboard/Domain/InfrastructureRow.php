<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Closure;
use Intakt\Core\Server\Contract\EndpointDown;
use Intakt\Core\Server\Contract\HubHealth;
use Intakt\Core\Server\Contract\HubVerdict;
use Intakt\Core\Server\Contract\ServerMetrics;
use Intakt\Core\Server\Contract\ServerReading;
use Intakt\Core\Server\Contract\WorkerThreads;

// The board's infrastructure row: a source that is down shows one down figure in place of its values, so the others still show theirs.
final readonly class InfrastructureRow
{
    private const string THREADS = 'Worker threads';
    private const string METRICS = 'Server metrics';
    private const string HUB = 'Hub';

    /**
     * @param list<InfrastructureFigure> $figures
     */
    private function __construct(
        public array $figures,
    ) {
    }

    /**
     * @param Closure(string): string $redact
     */
    public static function of(ServerReading $reading, Closure $redact): self
    {
        // Redacted as well, because the reason of a down source is free text the board does not control.
        return new self([
            ...self::threadsOf($reading->threads, $redact),
            ...self::metricsOf($reading->metrics, $redact),
            self::hubOf($reading->hub, $redact),
        ]);
    }

    public static function refused(string $reason): self
    {
        return new self([
            InfrastructureFigure::down(self::THREADS, $reason),
            InfrastructureFigure::down(self::METRICS, $reason),
            InfrastructureFigure::down(self::HUB, $reason),
        ]);
    }

    /**
     * @param Closure(string): string $redact
     *
     * @return list<InfrastructureFigure>
     */
    private static function threadsOf(WorkerThreads|EndpointDown $threads, Closure $redact): array
    {
        return $threads instanceof EndpointDown
            ? [InfrastructureFigure::down(self::THREADS, $redact($threads->reason))]
            : [new InfrastructureFigure('Threads busy', (string) $threads->busy), new InfrastructureFigure('Threads idle', (string) $threads->idle)];
    }

    /**
     * @param Closure(string): string $redact
     *
     * @return list<InfrastructureFigure>
     */
    private static function metricsOf(ServerMetrics|EndpointDown $metrics, Closure $redact): array
    {
        return $metrics instanceof EndpointDown
            ? [InfrastructureFigure::down(self::METRICS, $redact($metrics->reason))]
            : [new InfrastructureFigure('Queue depth', (string) $metrics->queueDepth), new InfrastructureFigure('Worker restarts', (string) $metrics->workerRestarts)];
    }

    /**
     * @param Closure(string): string $redact
     */
    private static function hubOf(HubHealth|EndpointDown $hub, Closure $redact): InfrastructureFigure
    {
        if ($hub instanceof EndpointDown) {
            return InfrastructureFigure::down(self::HUB, $redact($hub->reason));
        }

        return new InfrastructureFigure(self::HUB, $hub->value, HubVerdict::of($hub)->passes() ? FigureState::Fine : FigureState::Unhealthy);
    }
}
