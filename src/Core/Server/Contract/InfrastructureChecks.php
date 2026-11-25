<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The one home of the infrastructure health checks, judged from a reading the admin API's 2-second limit already bounds, so a piece that does not answer fails instead of hanging.
#[Exclude]
final readonly class InfrastructureChecks
{
    /**
     * @param list<string> $problems
     */
    private function __construct(
        public array $problems,
    ) {
    }

    public static function of(ServerReading $reading): self
    {
        return new self(array_values(array_filter([
            self::threadsProblem($reading->threads),
            self::metricsProblem($reading->metrics),
            HubVerdict::of($reading->hub)->problem,
        ], is_string(...))));
    }

    private static function threadsProblem(WorkerThreads|EndpointDown $threads): ?string
    {
        if ($threads instanceof EndpointDown) {
            return 'worker threads down ('.$threads->reason.')';
        }

        // A busy thread counts as ready, because it takes the next request once it is done; only threads that are all booting or restarting can take none.
        return 0 === $threads->busy + $threads->idle ? 'no worker thread is ready' : null;
    }

    private static function metricsProblem(ServerMetrics|EndpointDown $metrics): ?string
    {
        return $metrics instanceof EndpointDown ? 'server metrics down ('.$metrics->reason.')' : null;
    }
}
