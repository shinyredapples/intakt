<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

// The identity names the file itself, not its name, so a reader can tell when the job put another file in its place.
final readonly class JobOutputPiece
{
    public function __construct(
        public string $identity,
        public int $size,
        public string $bytes,
    ) {
    }
}
