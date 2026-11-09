<?php

declare(strict_types=1);

namespace Intakt\Core\Job\UseCase\SweepOrphanedJobs;

use Intakt\Core\Job\Infrastructure\DockerEngineApi;
use Intakt\Core\Job\Infrastructure\JobLocks;
use Intakt\Core\Job\Infrastructure\LabeledResource;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Lock\LockInterface;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;

// A job whose lock is free has no live owner, so an orchestrator that crashed mid-job leaves nothing behind once the next worker starts.
final readonly class SweepOrphanedJobs
{
    public function __construct(
        private DockerEngineApi $api,
        private JobLocks $locks,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<string>
     */
    #[AsEventListener(event: WorkerStartedEvent::class)]
    public function sweep(): array
    {
        [$containers, $networks] = $this->found();
        $swept = [];

        foreach ($this->jobIds([...$containers, ...$networks]) as $jobId) {
            if ($this->removedWithoutOwner($jobId, $containers, $networks)) {
                $swept[] = $jobId;
            }
        }

        return $swept;
    }

    // A host without a reachable engine must still start its worker, because most workers never run a job.
    /**
     * @return array{list<LabeledResource>, list<LabeledResource>}
     */
    private function found(): array
    {
        try {
            return [$this->api->jobContainers(), $this->api->jobNetworks()];
        } catch (ExceptionInterface $failure) {
            $this->logger->warning('The sweep could not list job containers, so nothing was swept.', ['exception' => $failure]);

            return [[], []];
        }
    }

    /**
     * @param list<LabeledResource> $resources
     *
     * @return list<string>
     */
    private function jobIds(array $resources): array
    {
        return array_values(array_unique(array_map(static fn (LabeledResource $resource): string => $resource->jobId, $resources)));
    }

    /**
     * @param list<LabeledResource> $containers
     * @param list<LabeledResource> $networks
     */
    private function removedWithoutOwner(string $jobId, array $containers, array $networks): bool
    {
        $lock = $this->locks->claim($jobId);

        if (!$lock instanceof LockInterface) {
            return false;
        }

        try {
            $this->removeAll($jobId, $containers, $networks);
        } finally {
            $lock->release();
        }

        return true;
    }

    /**
     * @param list<LabeledResource> $containers
     * @param list<LabeledResource> $networks
     */
    private function removeAll(string $jobId, array $containers, array $networks): void
    {
        foreach ($containers as $container) {
            if ($container->jobId === $jobId) {
                $this->quietly(fn () => $this->api->removeContainer($container->id), $container->id);
            }
        }

        foreach ($networks as $network) {
            if ($network->jobId === $jobId) {
                $this->quietly(fn () => $this->api->removeNetwork($network->id), $network->id);
            }
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
            $this->logger->warning('A job resource could not be removed by the sweep.', ['resource' => $id, 'exception' => $failure]);
        }
    }
}
