<?php

declare(strict_types=1);

namespace Intakt\Core\Server\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;

// Runs after the response is sent, so the request that crossed the ceiling is answered; outside a worker it does nothing, because exiting would end the whole process.
#[AsEventListener(priority: -1024)]
final readonly class EndWorkerOnMemoryCeiling
{
    private const int BYTES_PER_MEGABYTE = 1_048_576;

    public function __construct(
        private MemoryReading $memory,
        private WorkerExit $exit,
        #[Autowire('%env(int:WORKER_MEMORY_CEILING_MB)%')]
        private int $ceilingMegabytes,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        if (!$event->getRequest()->server->has('FRANKENPHP_WORKER')) {
            return;
        }

        if ($this->memory->bytes() > $this->ceilingMegabytes * self::BYTES_PER_MEGABYTE) {
            $this->exit->end();
        }
    }
}
