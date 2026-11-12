<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// The finished reviewer states, plus Stopped: a stopped reviewer ends with a verdict, so only the ledger tells it apart.
enum FinishedState: string
{
    case Verdict = 'verdict';
    case Failed = 'failed';
    case Blocked = 'blocked';
    case Stopped = 'stopped';
}
