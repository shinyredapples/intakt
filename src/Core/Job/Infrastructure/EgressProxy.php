<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\JobId;
use Intakt\Core\Job\Contract\JobSpec;

// One internal network and one proxy per job, so a job reaches only its own allowlist and never another job.
final readonly class EgressProxy
{
    public function __construct(
        private DockerEngine $engine,
        private EgressProxyDefinition $definition,
        private EgressLog $log,
        private JobLedger $ledger,
    ) {
    }

    public function open(JobSpec $spec): EgressRoute
    {
        if ($spec->egress->isNone()) {
            return EgressRoute::none();
        }

        $network = $this->networkOf($spec->id);
        $this->engine->createNetwork($network, $spec->id);
        $this->engine->start($this->engine->create($this->proxyOf($spec->id), $this->definition->of($spec, $network)));

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
}
