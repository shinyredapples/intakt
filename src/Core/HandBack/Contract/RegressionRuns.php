<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

use Intakt\Core\Git\Contract\Paths;

// The regression tests a head adds or changes, read from the diff, and how they ran against the base commit's code and against the head. Both runs are null when the head holds no test, which is a result and not a failure.
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
