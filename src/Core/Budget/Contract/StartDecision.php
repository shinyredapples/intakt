<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

enum StartDecision: string
{
    case Start = 'start';
    case AtCeiling = 'at-ceiling';
    case NoTrustedReading = 'no-trusted-reading';
    case ReadingNotFreshEnough = 'reading-not-fresh-enough';
    case NoMarginForParallelStart = 'no-margin-for-parallel-start';

    public function admits(): bool
    {
        return self::Start === $this;
    }
}
