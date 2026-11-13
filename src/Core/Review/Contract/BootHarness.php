<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Boots a commit with the harness it carries itself, as main is booted, so main's result can tell a broken harness from a broken head.
final readonly class BootHarness
{
    public function __construct(public BootAt $at)
    {
    }
}
