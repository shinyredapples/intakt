<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// A changed line as the change holds it at the head, with its place, so a reader can see what a dropped quote may have meant without the change text.
final readonly class NearLine
{
    public function __construct(
        public string $file,
        public int $line,
        public string $text,
    ) {
    }
}
