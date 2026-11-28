<?php

declare(strict_types=1);

namespace Intakt\Core\Dashboard\Contract;

// Who logs a send the hub refuses, because a caller that tries again must log only the refusal it gives up on, not one its retry recovers.
enum RefusalLogging: string
{
    case ByPublisher = 'publisher';
    case ByCaller = 'caller';
}
