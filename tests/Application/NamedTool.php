<?php

declare(strict_types=1);

namespace Intakt\Tests\Application;

final readonly class NamedTool
{
    public function __invoke(): string
    {
        return 'tool answer';
    }
}
