<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\UseCase\RunRegressionTests;

use Intakt\Core\Git\Contract\Paths;
use Intakt\Core\Job\Contract\JobId;

// The base gets the head's test files laid over it, because the base has none of them; the head is run as it is.
final readonly class RegressionCheckout
{
    private function __construct(
        public JobId $id,
        public string $revision,
        public Paths $tests,
        public Paths $overlay,
    ) {
    }

    public static function base(string $base, string $head, Paths $tests): self
    {
        return new self(new JobId('regression-base-'.substr($head, 0, 40)), $base, $tests, $tests);
    }

    public static function head(string $head, Paths $tests): self
    {
        return new self(new JobId('regression-head-'.substr($head, 0, 40)), $head, $tests, new Paths());
    }
}
