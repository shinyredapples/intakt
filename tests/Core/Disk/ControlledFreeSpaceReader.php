<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Disk;

use Intakt\Core\Disk\Infrastructure\FreeSpaceReader;
use Override;
use Symfony\Contracts\Service\ResetInterface;

// Readings a test sets, because a real disk cannot be filled to a floor on demand.
final class ControlledFreeSpaceReader implements FreeSpaceReader, ResetInterface
{
    private int $freeBytes = PHP_INT_MAX;

    private ?int $freeInodes = PHP_INT_MAX;

    public function __construct()
    {
    }

    #[Override]
    public function freeBytes(string $folder): int
    {
        return $this->freeBytes;
    }

    #[Override]
    public function freeInodes(string $folder): ?int
    {
        return $this->freeInodes;
    }

    public function report(int $freeBytes, ?int $freeInodes): void
    {
        $this->freeBytes = $freeBytes;
        $this->freeInodes = $freeInodes;
    }

    #[Override]
    public function reset(): void
    {
        $this->report(PHP_INT_MAX, PHP_INT_MAX);
    }
}
