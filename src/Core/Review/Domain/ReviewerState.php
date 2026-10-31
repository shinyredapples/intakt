<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

enum ReviewerState: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Verdict = 'verdict';
    case Failed = 'failed';

    public function isFinished(): bool
    {
        return match ($this) {
            self::Verdict, self::Failed => true,
            self::Queued, self::Running => false,
        };
    }
}
