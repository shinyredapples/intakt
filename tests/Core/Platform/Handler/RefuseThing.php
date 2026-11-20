<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Handler;

use Intakt\Core\Platform\Contract\CommandMessage;
use Intakt\Core\Platform\Contract\QueryMessage;

// Handled on both buses, because the unwrap test compares how each bus reports the same failure.
final readonly class RefuseThing implements CommandMessage, QueryMessage
{
    public function __construct(public ThingRefused $refusal)
    {
    }
}
