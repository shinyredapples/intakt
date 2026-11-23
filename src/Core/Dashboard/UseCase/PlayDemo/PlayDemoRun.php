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

    // Empty for a message queued before Plays carried a sign, because unserializing it leaves the property uninitialized and its page has no box to update.
    public function sign(): string
    {
        return $this->play ?? '';
    }
}
