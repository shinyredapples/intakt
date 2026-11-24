<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

use Intakt\Core\Git\Contract\Paths;

// Both runs are null when the head holds no test, because that is a result and not a failure; the tests are read from the diff, never from the builder's claim.
final readonly class RegressionRuns
{
    public function __construct(
        public Paths $tests,
        public ?RegressionRun $base,
        public ?RegressionRun $head,
    ) {
    }

    public static function noTest(): self
    {
        return new self(new Paths(), null, null);
    }

    public function foundNoTest(): bool
    {
        return $this->tests->isEmpty();
    }
}
