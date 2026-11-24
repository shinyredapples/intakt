<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

// Read from the job's output directory only, because the job ran model-written code; a run that left nothing readable is NoResult, never a pass or a fail.
enum RegressionRun: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    // The tests ran but reached no verdict (an error such as a missing fixture, a skip, no executed test), so the run neither reproduces the bug nor shows it fixed.
    case Errored = 'errored';
    case NoResult = 'no-result';
    case TimedOut = 'timed-out';
}
