<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Intakt\Core\HandBack\Contract\CheckJob;
use Intakt\Core\HandBack\Contract\RefusedHandBack;
use Intakt\Core\HandBack\Contract\RegressionRuns;
use Intakt\Core\HandBack\Contract\RegressionTests;
use Override;

// For build task tests that are not about the regression runs: a head holding no test.
final class NoRegressionTests implements RegressionTests
{
    #[Override]
    public function run(CheckJob $job): RegressionRuns|RefusedHandBack
    {
        return RegressionRuns::noTest();
    }
}
