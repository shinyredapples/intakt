<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Handler;

use Intakt\Core\Platform\Contract\CommandMessage;

final readonly class RefuseThing implements CommandMessage
{
    public function __construct(public ThingRefused $refusal)
    {
    }
}
