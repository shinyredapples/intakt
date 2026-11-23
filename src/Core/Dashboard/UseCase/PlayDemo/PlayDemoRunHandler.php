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
    // One and a half times the recorded pace, so the shipped recording fills the review page long enough to watch the findings arrive.
    public const float FACTOR = 1.5;

    public function __construct(
        private ReplaysRecording $replays,
        private RecordingFormat $format,
        private Filesystem $filesystem,
        private LoggerInterface $logger,
        #[Autowire(env: 'INTAKT_DEMO_RECORDING')]
        private string $recording,
        #[Autowire(env: 'INTAKT_COMPOSE_SERVICE')]
        private string $composeService,
    ) {
    }

    public function __invoke(): void
    {
        // Checked again here, because a message queued on the compose demo can reach a worker after the install left it.
        if ('' === $this->composeService) {
            $this->logger->error('The demo run was not played.', ['recording' => $this->recording, 'reason' => DemoReadiness::NotComposeDemo->sentence()]);

            return;
        }

        // Checked before reading, because the setting can be cleared after the message was queued and an empty path is no file.
        if ('' === $this->recording) {
            $this->logger->error('The demo run was not played.', ['recording' => $this->recording, 'reason' => DemoReadiness::NoRecording->sentence()]);

            return;
        }

        try {
            $this->replays->replay($this->format->read($this->filesystem->readFile($this->recording)), self::FACTOR);
        } catch (IOException|RecordingIsMalformed|ReplayIsRefused|ReplayStopped $failure) {
            // Logged and not thrown, because a retry would meet the same file and the same ledger and refuse again.
            $this->logger->error('The demo run was not played.', ['recording' => $this->recording, 'exception' => $failure]);
        }
    }
}
