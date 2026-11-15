<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\RecordDemo;

use Intakt\Core\Dashboard\Infrastructure\MercureRoundUpdates;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;

final readonly class CapturedUpdates
{
    public const string CAPTURE_VARIABLE = 'INTAKT_LIVE_CAPTURE';

    public function __construct(
        #[Autowire(env: self::CAPTURE_VARIABLE)]
        private string $capture,
        private RecordingFormat $format,
        private Filesystem $filesystem,
    ) {
    }

    /**
     * @param list<string> $rounds
     *
     * @return list<CapturedUpdate>
     *
     * @throws RecordingNeedsCapturedUpdates
     */
    public function ofRounds(array $rounds): array
    {
        $topics = array_map(MercureRoundUpdates::topicOf(...), $rounds);
        $updates = array_values(array_filter($this->captured(), static fn (CapturedUpdate $update): bool => in_array($update->topic, $topics, true)));

        return [] === $updates ? throw RecordingNeedsCapturedUpdates::noneOfTheRounds($this->capture) : $updates;
    }

    /**
     * @return list<CapturedUpdate>
     */
    private function captured(): array
    {
        $updates = [];

        foreach ($this->linesOfCapture() as $index => $line) {
            if ('' === $line) {
                continue;
            }

            $updates[] = $this->format->updateOf($line) ?? throw RecordingNeedsCapturedUpdates::malformed($this->capture, $index + 1);
        }

        return $updates;
    }

    /**
     * @return list<string>
     */
    private function linesOfCapture(): array
    {
        if ('' === $this->capture) {
            throw RecordingNeedsCapturedUpdates::notCaptured();
        }

        try {
            return explode("\n", $this->filesystem->readFile($this->capture));
        } catch (IOException $failure) {
            throw RecordingNeedsCapturedUpdates::unreadable($this->capture, $failure->getMessage());
        }
    }
}
