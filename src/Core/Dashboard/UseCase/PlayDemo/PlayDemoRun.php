<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\UseCase\PlayDemo;

use Intakt\Core\Platform\Contract\CommandMessage;

// Without fields, because the recording is the one the install configures and never a path a request could name.
final readonly class PlayDemoRun implements CommandMessage
{
}
