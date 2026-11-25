<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

use Closure;
use Intakt\Core\Server\Contract\HubHealth;
use Intakt\Core\Server\Contract\InfrastructureChecks;
use Intakt\Core\Server\Contract\ServerMetrics;
use Intakt\Core\Server\Contract\ServerReading;
use Intakt\Core\Server\Contract\WorkerThreads;

// The board's infrastructure row: a source that is down shows one down figure in place of its values, so the others still show theirs.
final readonly class InfrastructureRow
{
    private const string THREADS = 'Worker threads';
    private const string METRICS = 'Server metrics';
    private const string HUB = 'Hub';
    private const string FORGE = 'Forge';

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
        $checks = InfrastructureChecks::of($reading);
        // Redacted as well, because the reason of a down source is free text the board does not control.
        $down = static fn (string $name, string $reason): InfrastructureFigure => InfrastructureFigure::down($name, $redact($reason));

        return new self([
            ...$checks->threads->presented(
                static fn (WorkerThreads $threads): array => [new InfrastructureFigure('Threads busy', (string) $threads->busy), new InfrastructureFigure('Threads idle', (string) $threads->idle)],
                static fn (string $reason): array => [$down(self::THREADS, $reason)],
            ),
            ...$checks->metrics->presented(
                static fn (ServerMetrics $metrics): array => [new InfrastructureFigure('Queue depth', (string) $metrics->queueDepth), new InfrastructureFigure('Worker restarts', (string) $metrics->workerRestarts)],
                static fn (string $reason): array => [$down(self::METRICS, $reason)],
            ),
            $checks->hub->presented(
                static fn (HubHealth $hub): InfrastructureFigure => new InfrastructureFigure(self::HUB, $hub->value, $checks->hub->passes() ? FigureState::Fine : FigureState::Unhealthy),
                static fn (string $reason): InfrastructureFigure => $down(self::HUB, $reason),
            ),
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

    // Last, because it is the one figure judged by a call to the forge rather than by the admin API reading.
    public function withForge(?string $problem): self
    {
        return new self([
            ...$this->figures,
            null === $problem ? new InfrastructureFigure(self::FORGE, 'connected') : InfrastructureFigure::down(self::FORGE, $problem),
        ]);
    }
}
