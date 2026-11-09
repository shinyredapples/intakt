<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\EgressPolicy;
use Intakt\Core\Job\Contract\InvalidJobSpec;
use Intakt\Core\Job\Contract\JobEndCause;
use Intakt\Core\Job\Contract\JobOutcome;
use Intakt\Core\Job\Contract\JobRunner;
use Intakt\Core\Job\Contract\JobServices;
use Intakt\Core\Job\Contract\JobSpec;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

final readonly class DockerJobRunner implements JobRunner
{
    // A job stopped for its time limit is killed, which is exit 137 by convention.
    private const int KILLED_EXIT_CODE = 137;

    public function __construct(
        private DockerEngineApi $api,
        private JobContainerBody $containers,
        private EgressProxy $proxy,
        private StartCheckReader $startChecks,
        private JobLedger $ledger,
        private JobLocks $locks,
        private LoggerInterface $logger,
    ) {
    }

    public function run(JobSpec $spec): JobOutcome
    {
        $this->outputDirectoryMustExist($spec);
        $lock = $this->claimed($spec);
        $resources = new JobResources();

        try {
            return $this->ranIn($spec, $resources);
        } finally {
            $this->removed($resources);
            $lock->release();
        }
    }

    private function outputDirectoryMustExist(JobSpec $spec): void
    {
        if (!is_dir($spec->outputDir) || is_link($spec->outputDir)) {
            throw new InvalidJobSpec('The job output directory must be an existing directory.');
        }
    }

    private function claimed(JobSpec $spec): LockInterface
    {
        return $this->locks->claim($spec->id) ?? throw new InvalidJobSpec(sprintf('Job %s is already running.', $spec->id));
    }

    private function ranIn(JobSpec $spec, JobResources $resources): JobOutcome
    {
        $proxyId = EgressPolicy::Allowlist === $spec->egress ? $this->proxyStarted($spec, $resources) : null;
        $jobId = $this->api->createContainer('intakt-job-'.$spec->id, $this->containers->of($spec, $resources->network() ?? 'none'));
        $resources->addContainer($jobId);
        $this->api->start($jobId);

        [$exitCode, $cause] = $this->ended($spec, $jobId);
        $refusals = null === $proxyId ? [] : $this->proxy->refusalsIn($this->api->logs($proxyId));
        $outcome = new JobOutcome($spec->id, $exitCode, $cause, $this->startChecks->read($spec), $refusals, $spec->outputDir);
        $this->ledger->ended($outcome);

        return $outcome;
    }

    private function proxyStarted(JobSpec $spec, JobResources $resources): string
    {
        $network = $this->api->createInternalNetwork('intakt-job-'.$spec->id, [JobServices::JOB_LABEL => $spec->id]);
        $resources->setNetwork($network);

        $proxy = $this->api->createContainer('intakt-job-'.$spec->id.'-proxy', $this->proxy->containerBody($spec));
        $resources->addContainer($proxy);
        $this->api->connect($network, $proxy, EgressProxy::ALIAS);
        $this->api->start($proxy);

        return $proxy;
    }

    /**
     * @return array{int, JobEndCause}
     */
    private function ended(JobSpec $spec, string $jobId): array
    {
        $exitCode = $this->api->wait($jobId, $spec->timeoutSeconds);

        if (null === $exitCode) {
            $this->api->kill($jobId);

            return [self::KILLED_EXIT_CODE, JobEndCause::TimedOut];
        }

        return [$exitCode, $this->api->memoryKilled($jobId) ? JobEndCause::MemoryKilled : JobEndCause::Exited];
    }

    // One failed removal never stops the others; what stays is found by the sweep at the next start.
    private function removed(JobResources $resources): void
    {
        foreach ($resources->containers() as $container) {
            $this->quietly(fn () => $this->api->removeContainer($container), $container);
        }

        $network = $resources->network();

        if (null !== $network) {
            $this->quietly(fn () => $this->api->removeNetwork($network), $network);
        }
    }

    /**
     * @param callable(): mixed $removal
     */
    private function quietly(callable $removal, string $id): void
    {
        try {
            $removal();
        } catch (ExceptionInterface $failure) {
            $this->logger->warning('A job resource could not be removed and is left to the sweep.', ['resource' => $id, 'exception' => $failure]);
        }
    }
}
