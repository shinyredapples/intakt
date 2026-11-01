<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Domain;

enum JudgeCall: string
{
    case Act = 'act';
    case FollowUp = 'follow-up';
    case NotActedOn = 'rejected or unmarked';
}
