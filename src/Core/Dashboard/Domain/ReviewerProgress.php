<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

enum ReviewerProgress: string
{
    case Running = 'running';
    case Interrupted = 'interrupted';
    case Answered = 'verdict';
    case Failed = 'failed';
    case Blocked = 'blocked';
    case Stopped = 'stopped';
}
