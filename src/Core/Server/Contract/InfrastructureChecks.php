<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// The one home of the infrastructure health checks, judged from a reading the admin API's 2-second limit already bounds, so a piece that does not answer fails instead of hanging.
#[Exclude]
final readonly class InfrastructureChecks
{
    private const string ADMIN_FIX = 'check that FrankenPHP is running and that FRANKENPHP_ADMIN_URL points at its admin API';
    private const string NO_READY_THREAD_FIX = 'check the FrankenPHP log for a worker that fails to boot or keeps restarting';
    private const string METRICS_FIX = "check that the Caddyfile's global options turn on metrics and that FRANKENPHP_ADMIN_URL points at the admin API";

    /**
     * @var list<string>
     */
    public array $problems;

    /**
     * @param InfrastructureCheck<WorkerThreads> $threads
     * @param InfrastructureCheck<ServerMetrics> $metrics
     * @param InfrastructureCheck<HubHealth>     $hub
     */
    private function __construct(
        public InfrastructureCheck $threads,
        public InfrastructureCheck $metrics,
        public InfrastructureCheck $hub,
    ) {
        $this->problems = array_values(array_filter([$threads->problem, $metrics->problem, $hub->problem], is_string(...)));
    }

    public static function of(ServerReading $reading): self
    {
        return new self(
            self::threadsCheck($reading->threads),
            self::metricsCheck($reading->metrics),
            HubVerdict::of($reading->hub),
        );
    }

    /**
     * @return InfrastructureCheck<WorkerThreads>
     */
    private static function threadsCheck(WorkerThreads|EndpointDown $threads): InfrastructureCheck
    {
        if ($threads instanceof EndpointDown) {
            return InfrastructureCheck::unreadable($threads, 'worker threads down ('.$threads->reason.')', self::ADMIN_FIX);
        }

        // A busy thread counts as ready, because it takes the next request once it is done; only threads that are all booting or restarting can take none.
        return 0 === $threads->busy + $threads->idle
            ? InfrastructureCheck::failed($threads, 'no worker thread is ready', self::NO_READY_THREAD_FIX)
            : InfrastructureCheck::passed($threads);
    }

    /**
     * @return InfrastructureCheck<ServerMetrics>
     */
    private static function metricsCheck(ServerMetrics|EndpointDown $metrics): InfrastructureCheck
    {
        return $metrics instanceof EndpointDown
            ? InfrastructureCheck::unreadable($metrics, 'server metrics down ('.$metrics->reason.')', self::METRICS_FIX)
            : InfrastructureCheck::passed($metrics);
    }
}
