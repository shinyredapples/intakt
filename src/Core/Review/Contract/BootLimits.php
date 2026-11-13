<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Shared by the boot step that enforces them and the watcher, because the watcher's head lock must outlast the boots it runs while holding it.
final readonly class BootLimits
{
    public const int INSTALL_SECONDS = 600;
    public const int BOOT_SECONDS = 180;
    public const int LONGEST_SECONDS = self::INSTALL_SECONDS + self::BOOT_SECONDS;
}
