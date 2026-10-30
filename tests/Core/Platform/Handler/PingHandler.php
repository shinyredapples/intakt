<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Handler;

use Intakt\Core\Platform\Contract\AsCommandHandler;

#[AsCommandHandler(handles: Ping::class)]
final class PingHandler
{
    public function __invoke(): string
    {
        return 'pong';
    }
}
