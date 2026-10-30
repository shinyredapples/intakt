<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Platform\Handler;

final readonly class RenameThing
{
    public function __construct(public string $name)
    {
    }
}
