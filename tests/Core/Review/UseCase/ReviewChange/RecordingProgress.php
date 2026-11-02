<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Review\UseCase\ReviewChange;

use Intakt\Core\Review\Contract\ReviewProgress;
use Intakt\Core\Review\Contract\RoundProgress;

final class RecordingProgress implements ReviewProgress
{
    /** @var list<RoundProgress> */
    public array $reported = [];

    public function reached(RoundProgress $progress): void
    {
        $this->reported[] = $progress;
    }
}
