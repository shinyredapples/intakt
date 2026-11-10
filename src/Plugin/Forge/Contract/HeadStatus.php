<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// The commit status states a published verdict sets on its head (issue #1251, D1).
enum HeadStatus: string
{
    case Success = 'success';
    case Failure = 'failure';
    case Error = 'error';
}
