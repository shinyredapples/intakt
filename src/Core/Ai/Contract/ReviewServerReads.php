<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

// Answered from the CLI's own events, never from the model's text, so a reviewer cannot claim a read it did not make.
interface ReviewServerReads
{
    public function readThrough(ReviewServerAccess $access): bool;
}
