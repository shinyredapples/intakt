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
        private LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function publish(LiveEvent $event): void
    {
        $this->hub->publish($event);

        if ('' === $this->capture) {
            return;
        }

        try {
            // Locked, because every web and queue worker appends to the one file.
            $this->filesystem->appendToFile($this->capture, $this->format->lineOf(new CapturedUpdate($this->clock->now(), $event->topic, $event->turboStream)), true);
        } catch (Throwable $failure) {
            // Swallowed after a log, because a capture is best effort like the update itself and must never fail the work that published.
            $this->logger->warning('A live dashboard update was not captured.', ['topic' => $event->topic, 'exception' => $failure]);
        }
    }
}
