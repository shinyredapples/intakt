<?php

declare(strict_types=1);

namespace Intakt\Tests\Application\Parity\Fixtures;

use PHPUnit\Framework\TestCase;

// Not named *Test.php, so PHPUnit never collects it; the parity tests alias it to a test name.
abstract class AbstractCandidate extends TestCase
{
    public function testTheCandidateIsVisible(): bool
    {
        return true;
    }
}
