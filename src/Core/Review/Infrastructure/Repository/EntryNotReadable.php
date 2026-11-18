<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Infrastructure\Repository;

use RuntimeException;
use Throwable;

// A refusal the model gets back as a tool error, because it names an entry of the head that the tool cannot answer with.
final class EntryNotReadable extends RuntimeException
{
    public static function because(string $path, string $reason, ?Throwable $previous = null): self
    {
        return new self(sprintf('"%s" %s.', $path, $reason), previous: $previous);
    }
}
