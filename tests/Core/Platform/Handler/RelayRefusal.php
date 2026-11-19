<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Handler;

final readonly class RelayRefusal
{
    public function __construct(public ThingRefused $refusal)
    {
    }
}
