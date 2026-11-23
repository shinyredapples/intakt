<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

// History only for a legacy spelling kept apart, because storage numbers new rounds of its pull request in the canonical review and would refuse or misplace a number counted from the old spelling.
enum Timeline: string
{
    case Current = 'current';
    case HistoryOnly = 'history_only';
}
