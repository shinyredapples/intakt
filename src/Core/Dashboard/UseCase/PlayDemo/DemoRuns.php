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
    // The query field Play's redirect carries; only a /demo request naming it reads the start line, so a visit from another tab cannot take the follow first.
    public const string PLAY = 'play';
    // Its own type, never the generic success line, because the demo page follows the run to the review only on the page Play's redirect reaches.
    private const string FLASH_STARTED = 'demo-started-';
    private const string FLASH_ERROR = 'error';
    private const string PLAY_SHAPE = '/^[0-9a-f]{32}$/D';

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

    // A fixed-length sign after a fixed prefix, so no sign a request sends can name another flash type.
    public static function startedFlash(string $play): ?string
    {
        return 1 === preg_match(self::PLAY_SHAPE, $play) ? self::FLASH_STARTED.$play : null;
    }

    // The sign only when it has the shape Play makes, because a request names it and it becomes an element id.
    public static function playOf(string $play): ?string
    {
        return 1 === preg_match(self::PLAY_SHAPE, $play) ? $play : null;
    }

    /**
     * @return array{string, string}
     */
    public function play(string $play): array
    {
        $readiness = $this->readiness();

        if (DemoReadiness::Ready !== $readiness) {
            return [self::FLASH_ERROR, $readiness->sentence()];
        }

        // Sent to a queue worker, because a replay sleeps at the recorded pace for minutes and must never hold a web worker.
        $this->commands->dispatch(new PlayDemoRun($play));

        return [self::FLASH_STARTED.$play, 'The demo run is starting: the caption at the bottom of each page names the stage it is in.'];
    }
}
