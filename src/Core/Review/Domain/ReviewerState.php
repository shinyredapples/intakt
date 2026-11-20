<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Domain;

enum ReviewerState: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Interrupted = 'interrupted';
    case Verdict = 'verdict';
    case Failed = 'failed';
    case Blocked = 'blocked';

    public function isFinished(): bool
    {
        return match ($this) {
            self::Verdict, self::Failed, self::Blocked => true,
            self::Queued, self::Running, self::Interrupted => false,
        };
    }

    public function hasStarted(): bool
    {
        return self::Queued !== $this;
    }
}
