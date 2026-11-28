<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

// The file a watch follows, read in pieces from where its last look ended, so a look never holds more than one piece.
interface JobFollowedFile
{
    // Null when the file is missing, no plain file, or one the orchestrator cannot open.
    public function read(int $from, int $maxBytes): ?JobOutputPiece;
}
