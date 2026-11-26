<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Disk;

use Intakt\Core\Disk\Contract\FreeSpaceReader;
use Override;

final class FreeSpaceInMemory implements FreeSpaceReader
{
    /** @var list<string> */
    public array $asked = [];

    public function __construct(
        private int $bytes,
        private int $inodes,
    ) {
    }

    public function leave(int $bytes, int $inodes): void
    {
        $this->bytes = $bytes;
        $this->inodes = $inodes;
    }

    #[Override]
    public function freeBytes(string $folder): int
    {
        $this->asked[] = $folder;

        return $this->bytes;
    }

    #[Override]
    public function freeInodes(string $folder): int
    {
        $this->asked[] = $folder;

        return $this->inodes;
    }
}
