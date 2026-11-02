<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Intakt\Core\Server\Contract\EndpointDown;
use Intakt\Core\Server\Contract\HubHealth;
use Intakt\Core\Server\Contract\ServerMetrics;
use Intakt\Core\Server\Contract\ServerReading;
use Intakt\Core\Server\Contract\ServerStatus;
use Intakt\Core\Server\Contract\WorkerThreads;
use NoDiscard;

final readonly class AdminApiServerStatus implements ServerStatus
{
    private const string THREADS = '/frankenphp/threads';
    private const string METRICS = '/metrics';
    private const string HUB_READY = '/mercure/health/ready';
    private const string WORKER_THREAD = 'Worker PHP Thread';

    public function __construct(
        private AdminApi $adminApi,
    ) {
    }

    #[NoDiscard('a source that is down is only seen in the reading')]
    public function read(): ServerReading
    {
        return new ServerReading($this->workerThreads(), $this->metrics(), $this->hubHealth());
    }

    private function workerThreads(): WorkerThreads|EndpointDown
    {
        $body = $this->body(self::THREADS);
        if ($body instanceof EndpointDown) {
            return $body;
        }
        $states = $this->workerStates($body);
        if (null === $states) {
            return $this->unusable(self::THREADS);
        }

        $busy = count(array_filter($states, static fn (array $state): bool => $state['busy']));
        // A booting or restarting thread is neither, because it can take no request yet.
        $idle = count(array_filter($states, static fn (array $state): bool => !$state['busy'] && 'ready' === $state['state']));

        return new WorkerThreads($busy, $idle);
    }

    private function metrics(): ServerMetrics|EndpointDown
    {
        $body = $this->body(self::METRICS);
        if ($body instanceof EndpointDown) {
            return $body;
        }
        $samples = PrometheusSamples::fromText($body);
        if (!$samples instanceof PrometheusSamples || !$samples->has('frankenphp_queue_depth')) {
            return $this->unusable(self::METRICS);
        }
        $queueDepth = $samples->sum('frankenphp_queue_depth', 'frankenphp_worker_queue_depth');
        $workerRestarts = $samples->sum('frankenphp_worker_restarts');
        if (null === $queueDepth || null === $workerRestarts) {
            return $this->unusable(self::METRICS);
        }

        return new ServerMetrics($queueDepth, $workerRestarts);
    }

    private function hubHealth(): HubHealth|EndpointDown
    {
        $answer = $this->adminApi->get(self::HUB_READY);
        if ($answer instanceof EndpointDown) {
            return $answer;
        }

        return match ($answer['status']) {
            200 => $this->reportedHealth($answer['body']),
            503 => HubHealth::Unhealthy,
            default => $this->refused(self::HUB_READY, $answer['status']),
        };
    }

    private function body(string $path): string|EndpointDown
    {
        $answer = $this->adminApi->get($path);
        if ($answer instanceof EndpointDown) {
            return $answer;
        }

        return 200 === $answer['status'] ? $answer['body'] : $this->refused($path, $answer['status']);
    }

    /**
     * @return list<array{busy: bool, state: string}>|null
     */
    private function workerStates(string $body): ?array
    {
        $decoded = json_decode($body, true);
        $threads = is_array($decoded) ? ($decoded['ThreadDebugStates'] ?? null) : null;
        if (!is_array($threads)) {
            return null;
        }

        $states = [];
        foreach ($threads as $thread) {
            if (!is_array($thread) || !is_string($thread['Name'] ?? null) || !is_string($thread['State'] ?? null) || !is_bool($thread['IsBusy'] ?? null)) {
                return null;
            }
            if (str_starts_with($thread['Name'], self::WORKER_THREAD)) {
                $states[] = ['busy' => $thread['IsBusy'], 'state' => $thread['State']];
            }
        }

        return $states;
    }

    private function reportedHealth(string $body): HubHealth|EndpointDown
    {
        $decoded = json_decode($body, true);
        $status = is_array($decoded) ? ($decoded['status'] ?? null) : null;
        if (!is_string($status)) {
            return $this->unusable(self::HUB_READY);
        }

        return 'ok' === $status ? HubHealth::Healthy : HubHealth::Unhealthy;
    }

    private function refused(string $path, int $status): EndpointDown
    {
        return new EndpointDown(sprintf('%s answered with status %d', $path, $status));
    }

    private function unusable(string $path): EndpointDown
    {
        return new EndpointDown($path.' answered in an unusable shape');
    }
}
