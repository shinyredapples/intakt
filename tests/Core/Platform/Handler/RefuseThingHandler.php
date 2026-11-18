<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Handler;

use Intakt\Core\Platform\Contract\AsCommandHandler;
use Intakt\Core\Platform\Contract\AsQueryHandler;

#[AsCommandHandler]
#[AsQueryHandler]
final class RefuseThingHandler
{
    public function __invoke(RefuseThing $command): never
    {
        throw $command->refusal;
    }
}
