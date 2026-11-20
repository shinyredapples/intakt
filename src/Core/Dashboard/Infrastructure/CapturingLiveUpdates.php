<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Infrastructure;

use Intakt\Core\Dashboard\Contract\LiveEvent;
use Intakt\Core\Dashboard\Contract\LiveUpdates;
use Intakt\Core\Dashboard\UseCase\RecordDemo\CapturedUpdate;
use Intakt\Core\Dashboard\UseCase\RecordDemo\CapturedUpdates;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingFormat;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;
use Throwable;

// Writes beside the hub only while the owner captures a review for the demo recording, because the ledger does not hold the live updates a recording must keep.
#[AsDecorator(LiveUpdates::class)]
final readonly class CapturingLiveUpdates implements LiveUpdates
{
    public function __construct(
        #[AutowireDecorated]
        private LiveUpdates $hub,
        #[Autowire(env: CapturedUpdates::CAPTURE_VARIABLE)]
        private string $capture,
        private RecordingFormat $format,
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private LockFactory $locks,
        private LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function publish(LiveEvent $event): void
    {
        if ('' === $this->capture) {
            $this->hub->publish($event);

            return;
        }

        $lock = $this->locks->createLock('dashboard-live-capture');

        // Waited for, and held across the publish and the append, because two workers publishing between each other's two steps would capture their updates in the other order than the hub sent them.
        if (!$this->capturedOrLogged($event, static fn () => $lock->acquire(true))) {
            $this->hub->publish($event);

            return;
        }

        try {
            $this->hub->publish($event);
            $this->capturedOrLogged($event, fn () => $this->filesystem->appendToFile($this->capture, $this->format->lineOf(new CapturedUpdate($this->clock->now(), $event->topic, $event->turboStream))));
        } finally {
            $this->released($lock, $event);
        }
    }

    private function capturedOrLogged(LiveEvent $event, callable $step): bool
    {
        try {
            $step();

            return true;
            // Boundary: logs a failed capture and swallows it, because a capture is best effort like the update itself and must never fail the work that published.
        } catch (Throwable $failure) {
            $this->logger->warning('A live dashboard update was not captured.', ['topic' => $event->topic, 'exception' => $failure]);

            return false;
        }
    }

    private function released(LockInterface $lock, LiveEvent $event): void
    {
        try {
            $lock->release();
            // Boundary: logs a failed lock release and swallows it, because the update already went out and the lock expires on its own.
        } catch (Throwable $failure) {
            $this->logger->warning('The live capture lock was not released.', ['topic' => $event->topic, 'exception' => $failure]);
        }
    }
}
