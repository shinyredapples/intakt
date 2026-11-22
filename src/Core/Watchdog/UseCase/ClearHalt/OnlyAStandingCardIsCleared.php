<?php

declare(strict_types=1);

namespace Intakt\Core\Watchdog\UseCase\ClearHalt;

use RuntimeException;

final class OnlyAStandingCardIsCleared extends RuntimeException
{
}
