<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Infrastructure;

use Intakt\Core\Job\Contract\JobFollowedFile;
use Intakt\Core\Job\Contract\JobOutputPiece;
use Intakt\Core\Job\Contract\JobWatch;

// Binds a caller's watch to the folder of the job it watches, so the container code never reads the output itself.
final readonly class LiveOutputWatch implements JobFollowedFile
{
    public function __construct(
        private JobWatch $watch,
        private JobOutputDirectories $outputs,
        private string $directory,
    ) {
    }

    public function intervalSeconds(): int
    {
        return $this->watch->intervalSeconds();
    }

    public function endsSession(): bool
    {
        return $this->watch->endsSession($this);
    }

    public function read(int $from, int $maxBytes): ?JobOutputPiece
    {
        return $this->outputs->piece($this->directory.'/'.$this->watch->followedFile(), $from, $maxBytes);
    }
}
