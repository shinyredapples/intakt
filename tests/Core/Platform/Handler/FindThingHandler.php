<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Handler;

use Intakt\Core\Platform\Contract\AsQueryHandler;

#[AsQueryHandler(handles: FindThing::class)]
final class FindThingHandler
{
    public function __invoke(): string
    {
        return 'thing';
    }
}
