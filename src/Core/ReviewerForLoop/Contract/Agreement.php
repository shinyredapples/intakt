<?php

declare(strict_types=1);

namespace Intakt\Core\ReviewerForLoop\Contract;

enum Agreement: string
{
    case Agree = 'agree';
    case Disagree = 'disagree';
    case Incomplete = 'incomplete';
}
