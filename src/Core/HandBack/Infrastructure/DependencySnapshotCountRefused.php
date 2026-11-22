<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Infrastructure;

use InvalidArgumentException;

final class DependencySnapshotCountRefused extends InvalidArgumentException
{
    public static function belowOne(int $count): self
    {
        return new self(sprintf('the dependency snapshot store must keep at least 1 snapshot, %d given', $count));
    }
}
