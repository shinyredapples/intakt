<?php

declare(strict_types=1);

namespace Intakt\Tests\Journeys\Support;

use RuntimeException;

final class RecordNotPublished extends RuntimeException
{
    public function __construct(string $file)
    {
        parent::__construct(sprintf('The scripted call record %s could not be published.', $file));
    }
}
