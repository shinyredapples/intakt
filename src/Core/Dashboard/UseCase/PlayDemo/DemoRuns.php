<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\PlayDemo;

use Intakt\Core\Dashboard\Domain\TourCaption;
use Intakt\Core\Dashboard\Infrastructure\DemoTour;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class DemoRuns
{
    private const string FLASH_SUCCESS = 'success';
    private const string FLASH_ERROR = 'error';

    public function __construct(
        private DemoTour $tour,
        private Filesystem $filesystem,
        private MessageBusInterface $commands,
        #[Autowire(env: 'INTAKT_DEMO_RECORDING')]
        private string $recording,
    ) {
    }

    public function readiness(): DemoReadiness
    {
        $caption = $this->tour->caption();

        return match (true) {
            !$caption instanceof TourCaption => DemoReadiness::NotComposeDemo,
            $caption->isPlayed() => DemoReadiness::Played,
            '' === $this->recording || !$this->filesystem->exists($this->recording) => DemoReadiness::NoRecording,
            default => DemoReadiness::Ready,
        };
    }

    /**
     * @return array{string, string}
     */
    public function play(): array
    {
        $readiness = $this->readiness();

        if (DemoReadiness::Ready !== $readiness) {
            return [self::FLASH_ERROR, $readiness->sentence()];
        }

        // Sent to a queue worker, because a replay sleeps at the recorded pace for minutes and must never hold a web worker.
        $this->commands->dispatch(new PlayDemoRun());

        return [self::FLASH_SUCCESS, 'The demo run is starting: the caption at the bottom of each page names the stage it is in.'];
    }
}
