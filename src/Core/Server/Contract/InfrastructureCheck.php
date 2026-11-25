<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Contract;

use Closure;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * @template-covariant T of WorkerThreads|ServerMetrics|HubHealth = never
 */
#[Exclude]
final readonly class InfrastructureCheck
{
    /**
     * @phpstan-param T|EndpointDown $source
     */
    private function __construct(
        private WorkerThreads|ServerMetrics|HubHealth|EndpointDown $source,
        public ?string $problem,
        public ?string $fix,
    ) {
    }

    /**
     * @template S of WorkerThreads|ServerMetrics|HubHealth
     *
     * @param S $source
     *
     * @return self<S>
     */
    public static function passed(WorkerThreads|ServerMetrics|HubHealth $source): self
    {
        return new self($source, null, null);
    }

    /**
     * @template S of WorkerThreads|ServerMetrics|HubHealth
     *
     * @param S $source
     *
     * @return self<S>
     */
    public static function failed(WorkerThreads|ServerMetrics|HubHealth $source, string $problem, string $fix): self
    {
        return new self($source, $problem, $fix);
    }

    /**
     * @return self<never>
     */
    public static function unreadable(EndpointDown $down, string $problem, string $fix): self
    {
        return new self($down, $problem, $fix);
    }

    public function passes(): bool
    {
        return null === $this->problem;
    }

    // Callers get the reading or the down reason from here, because deciding which they have is part of the check and must have no second copy (decision D1 of #3826).
    /**
     * @template R
     *
     * @param Closure(T): R      $read
     * @param Closure(string): R $down
     *
     * @return R
     */
    public function presented(Closure $read, Closure $down): mixed
    {
        return $this->source instanceof EndpointDown ? $down($this->source->reason) : $read($this->source);
    }
}
