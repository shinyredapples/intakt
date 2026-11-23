<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Handler;

use Intakt\Core\Platform\Contract\CommandMessage;

final readonly class RenameThing implements CommandMessage
{
    public function __construct(public string $name)
    {
    }
}
