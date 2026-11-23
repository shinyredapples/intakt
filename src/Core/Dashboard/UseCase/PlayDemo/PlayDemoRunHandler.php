<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\PlayDemo;

use Intakt\Core\Dashboard\Infrastructure\ReplayMarks;
use Intakt\Core\Dashboard\Infrastructure\TourCaptionUpdates;
use Intakt\Core\Dashboard\UseCase\CreateDemoProject\CreateDemoProjectCommand;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingFormat;
use Intakt\Core\Dashboard\UseCase\RecordDemo\RecordingIsMalformed;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplayIsRefused;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplaysRecording;
use Intakt\Core\Dashboard\UseCase\ReplayDemo\ReplayStopped;
use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Review\Contract\FlagProjectAsReplay;
use Intakt\Core\Review\Contract\ReplayMark;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Throwable;

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
        private ReplayMarks $marks,
        private MessageBusInterface $commands,
        private TourCaptionUpdates $captions,
        #[Autowire(env: 'INTAKT_DEMO_RECORDING')]
        private string $recording,
        #[Autowire(env: 'INTAKT_COMPOSE_SERVICE')]
        private string $composeService,
    ) {
    }

    public function __invoke(PlayDemoRun $run): void
    {
        // Checked again here, because a message queued on the compose demo can reach a worker after the install left it.
        if ('' === $this->composeService) {
            $this->refuseWithout($run, DemoReadiness::NotComposeDemo->sentence());

            return;
        }

        // Checked before reading, because the setting can be cleared after the message was queued and an empty path is no file.
        if ('' === $this->recording) {
            $this->refuseWithout($run, DemoReadiness::NoRecording->sentence());

            return;
        }

        try {
            $this->replays->replay($this->format->read($this->filesystem->readFile($this->recording)), self::FACTOR, $this->recreateAMissingDemoProject(...));
        } catch (IOException|RecordingIsMalformed|ReplayIsRefused|ReplayStopped $failure) {
            $this->refuse($run, $failure);
        }
    }

    private function refuseWithout(PlayDemoRun $run, string $reason): void
    {
        $this->logger->error('The demo run was not played.', ['recording' => $this->recording, 'reason' => $reason]);
        $this->publish($run, $reason);
    }

    private function publish(PlayDemoRun $run, string $reason): void
    {
        if ('' !== $run->play) {
            $this->captions->publishTheRefusal($run->play, $reason);
        }
    }

    // Play stands for the whole demo, so a demo project removed with intakt:demo:remove comes back instead of refusing the run; run by the replay under its lock, after the recording was accepted, so a Play that loses the lock changes nothing.
    private function recreateAMissingDemoProject(): void
    {
        if (ReplayMark::Replay !== $this->marks->ofProject(CreateDemoProjectCommand::NAME)) {
            $this->commands->dispatch(new FlagProjectAsReplay(CreateDemoProjectCommand::NAME));
        }
    }

    // Logged and not thrown, because a retry would meet the same file and the same ledger and refuse again; shown on the page of this Play, because the person who pressed Play reads no log, with a fixed sentence because the exception can name the recording's path.
    private function refuse(PlayDemoRun $run, Throwable $failure): void
    {
        $this->logger->error('The demo run was not played.', ['recording' => $this->recording, 'exception' => $failure]);
        $this->publish($run, DemoRefusal::of($failure)->sentence());
    }
}
