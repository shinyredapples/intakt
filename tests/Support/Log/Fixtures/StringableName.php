<?php

declare(strict_types=1);

namespace Intakt\Tests\Support\Log\Fixtures;

use Stringable;

final readonly class StringableName implements Stringable
{
    public function __toString(): string
    {
        return 'stringable name';
    }
}
