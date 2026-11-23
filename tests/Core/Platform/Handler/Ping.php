<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Handler;

use Intakt\Core\Platform\Contract\CommandMessage;

final readonly class Ping implements CommandMessage
{
}
