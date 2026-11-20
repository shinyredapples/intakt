<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Handler;

use Intakt\Core\Platform\Contract\QueryMessage;

final readonly class FindRefusedThing implements QueryMessage
{
    public function __construct(public ThingRefused $refusal)
    {
    }
}
