<?php

declare(strict_types=1);

namespace Intakt\Tests\Application\Parity\Fixtures;

use PHPUnit\Framework\TestCase;

abstract class AbstractCandidateTest extends TestCase
{
    public function testVisible(): bool
    {
        return true;
    }
}
