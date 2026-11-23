<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\PlayDemo;

use Intakt\Core\Platform\Contract\CommandMessage;

// Carries only the Play's own sign, because the recording is the one the install configures and never a path a request could name; the sign names the box on the page this Play's refusal updates.
final readonly class PlayDemoRun implements CommandMessage
{
    public function __construct(public string $play)
    {
    }

    /**
     * @return array{play: string}
     */
    public function __serialize(): array
    {
        return ['play' => $this->play];
    }

    // Empty for a message queued before Plays carried a sign, because it holds no property and its page has no box to update.
    /**
     * @param array<array-key, mixed> $data
     */
    public function __unserialize(array $data): void
    {
        $this->play = is_string($data['play'] ?? null) ? $data['play'] : '';
    }
}
