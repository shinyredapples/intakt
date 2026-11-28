<?php

declare(strict_types=1);

trait Checks
{
    public function testRuns(): void
    {
    }
}

final class HiddenTest
{
    use Checks {
        testRuns as private;
        testRuns as private testHidden;
    }
}
