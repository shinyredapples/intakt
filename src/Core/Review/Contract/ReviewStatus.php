<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

enum ReviewStatus: string
{
    case InReview = 'in-review';
    case WaitingOnYou = 'waiting-on-you';
}
