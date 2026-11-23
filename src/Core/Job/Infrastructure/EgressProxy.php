<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\DockerEngineFailed;
use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobSpec;
use Symfony\Component\Clock\ClockInterface;

// One internal network and one proxy per job, so a job reaches only its own allowlist and never another job.
final readonly class EgressProxy
{
    // As long as the health check's start period, after which the engine would count a failed check against the proxy.
    private const int READY_SECONDS = 30;
    private const float POLL_SECONDS = 0.25;

    public function __construct(
        private DockerEngine $engine,
        private EgressProxyDefinition $definition,
        private EgressLog $log,
        private JobLedger $ledger,
        private ClockInterface $clock,
    ) {
    }

    public function open(JobSpec $spec): EgressRoute
    {
        if ($spec->egress->isNone()) {
            return EgressRoute::none();
        }

        $network = $this->networkOf($spec->id);
        $proxy = $this->proxyOf($spec->id);
        $this->engine->createNetwork($network, $spec->id);
        $this->engine->start($this->engine->create($proxy, $this->definition->of($spec, $network)));
        $this->awaitHealthy($proxy);

        return EgressRoute::through($network, sprintf('http://%s:%d', EgressProxyDefinition::ALIAS, EgressProxyDefinition::PORT));
    }

    public function recordRefusals(JobSpec $spec): void
    {
        if ($spec->egress->isNone()) {
            return;
        }

        $log = $this->engine->logs($this->proxyOf($spec->id), EgressLog::LINES);

        foreach ($this->log->refusalsIn($log) as $refusal) {
            $this->ledger->egressRefused($spec->id, $refusal);
        }
    }

    // By name, with an absent one accepted, so a run that failed half-way through open() leaves nothing behind either.
    public function close(JobSpec $spec): void
    {
        if ($spec->egress->isNone()) {
            return;
        }

        $this->engine->remove($this->proxyOf($spec->id));
        $this->engine->removeNetwork($this->networkOf($spec->id));
    }

    private function networkOf(JobId $job): string
    {
        return 'intakt-job-'.$job->value;
    }

    private function proxyOf(JobId $job): string
    {
        return 'intakt-egress-'.$job->value;
    }

    // The engine reports a proxy started before Squid listens, and a proxy that stops on a bad config leaves the job without a way out and without a cause, so the job waits for its health check.
    private function awaitHealthy(string $proxy): void
    {
        $deadline = $this->clock->now()->modify(sprintf('+%d seconds', self::READY_SECONDS));

        $health = $this->engine->health($proxy);

        while (ContainerHealth::Starting === $health && $this->clock->now() < $deadline) {
            $this->clock->sleep(self::POLL_SECONDS);
            $health = $this->engine->health($proxy);
        }

        $reason = match ($health) {
            ContainerHealth::Healthy => null,
            ContainerHealth::Starting => sprintf('it was still starting after %d seconds', self::READY_SECONDS),
            ContainerHealth::Unhealthy => 'it is unhealthy',
            ContainerHealth::Stopped => 'it stopped',
        };

        if (null !== $reason) {
            throw DockerEngineFailed::proxyNotReady($proxy, $reason);
        }
    }
}
