<?php

declare(strict_types=1);

namespace Intakt\Core\Disk\Contract;

use NoDiscard;

interface FreeSpaceReader
{
    #[NoDiscard]
    public function freeBytes(string $folder): int;

    #[NoDiscard]
    public function freeInodes(string $folder): int;
}
