<?php

declare(strict_types=1);

namespace Intakt\Tests\Application\Parity\Fixtures;

// Not named *Test.php, so PHPUnit never collects it; the parity tests alias it to a test name.
final class PlainCandidate
{
    public function testVisible(): bool
    {
        return true;
    }
}
