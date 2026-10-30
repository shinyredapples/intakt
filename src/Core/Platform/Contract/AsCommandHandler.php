<?php

declare(strict_types=1);

namespace Intakt\Core\Platform\Contract;

use Attribute;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class AsCommandHandler extends AsMessageHandler
{
    public function __construct(?string $handles = null, ?string $method = null, int $priority = 0)
    {
        parent::__construct(bus: Bus::Command->value, handles: $handles, method: $method, priority: $priority);
    }
}
