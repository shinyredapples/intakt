<?php

declare(strict_types=1);

namespace Intakt\Tests\Core\Build\UseCase\BuildTask;

use Intakt\Core\HandBack\Contract\CheckJob;
use Intakt\Core\HandBack\Contract\RegressionRuns;
use Intakt\Core\HandBack\Contract\RegressionTests;
use Override;

// A head holding no test, because the build task tests that use it are not about the regression runs.
final class NoRegressionTests implements RegressionTests
{
    #[Override]
    public function run(CheckJob $job): RegressionRuns
    {
        return RegressionRuns::noTest();
    }
}
