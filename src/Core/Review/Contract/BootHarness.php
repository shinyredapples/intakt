<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Platform\Contract\QueryMessage;

// Boots a commit with the harness it carries itself, as main is booted, so main's result can tell a broken harness from a broken head.
final readonly class BootHarness implements QueryMessage
{
    public function __construct(public BootAt $at)
    {
    }
}
