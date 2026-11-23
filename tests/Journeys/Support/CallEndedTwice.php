<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use RuntimeException;

final class CallEndedTwice extends RuntimeException
{
    public function __construct(int $call)
    {
        parent::__construct(sprintf('Scripted call %d already has an end record.', $call));
    }
}
