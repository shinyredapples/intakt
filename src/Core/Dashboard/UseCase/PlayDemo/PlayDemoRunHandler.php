<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\PlayDemo;

use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingFormat;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingIsMalformed;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplayIsRefused;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplaysRecording;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplayStopped;
use Intakt\Core\Platform\Contract\AsCommandHandler;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

#[AsCommandHandler(handles: PlayDemoRun::class)]
final readonly class PlayDemoRunHandler
{
    // Four times the recorded pace, so a run of a few minutes fits a short look at the dashboard.
    public const float FACTOR = 4.0;

    public function __construct(
        private ReplaysRecording $replays,
        private RecordingFormat $format,
        private Filesystem $filesystem,
        private LoggerInterface $logger,
        #[Autowire(env: 'INTAKT_DEMO_RECORDING')]
        private string $recording,
    ) {
    }

    public function __invoke(): void
    {
        try {
            $this->replays->replay($this->format->read($this->filesystem->readFile($this->recording)), self::FACTOR);
        } catch (IOException|RecordingIsMalformed|ReplayIsRefused|ReplayStopped $failure) {
            // Logged and not thrown, because a retry would meet the same file and the same ledger and refuse again.
            $this->logger->error('The demo run was not played.', ['recording' => $this->recording, 'exception' => $failure]);
        }
    }
}
