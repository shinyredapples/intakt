<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

enum ReviewerProgress: string
{
    case Running = 'running';
    case Answered = 'verdict';
    case Failed = 'failed';
    case Blocked = 'blocked';
}
