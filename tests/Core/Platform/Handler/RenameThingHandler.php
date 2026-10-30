<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Handler;

use Intakt\Core\Platform\Contract\AsCommandHandler;

#[AsCommandHandler]
final class RenameThingHandler
{
    public function __invoke(RenameThing $command): string
    {
        return strtoupper($command->name);
    }
}
