<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Closure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

// Runs after the response is sent, so the request that crossed the ceiling is answered; outside a worker it does nothing, because exiting would end the whole process.
#[AsEventListener(priority: -1024)]
final readonly class EndWorkerOnMemoryCeiling
{
    private const int BYTES_PER_MEGABYTE = 1_048_576;

    /**
     * @param (Closure(): int)|null  $memoryBytes
     * @param (Closure(): void)|null $end
     */
    public function __construct(
        #[Autowire('%env(int:WORKER_MEMORY_CEILING_MB)%')]
        private int $ceilingMegabytes,
        // Replaced only by a test, because a real reading cannot be forced and a real end would leave the test process.
        private ?Closure $memoryBytes = null,
        private ?Closure $end = null,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        if (!$event->getRequest()->server->has('FRANKENPHP_WORKER')) {
            return;
        }

        if ($this->usedBytes() > $this->ceilingMegabytes * self::BYTES_PER_MEGABYTE) {
            $this->endWorker();
        }
    }

    // The true size is read because the container is charged for what the worker holds from the system, not for what PHP uses.
    private function usedBytes(): int
    {
        return $this->memoryBytes instanceof Closure ? ($this->memoryBytes)() : memory_get_usage(true);
    }

    // Status 0 is a normal end, so FrankenPHP starts a new worker and counts no failure toward max_consecutive_failures.
    private function endWorker(): void
    {
        $this->end instanceof Closure ? ($this->end)() : exit(0);
    }
}
