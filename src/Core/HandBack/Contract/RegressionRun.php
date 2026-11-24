<?php

declare(strict_types=1);

namespace Intakt\Core\HandBack\Contract;

// Read from the job's output directory only, because the job ran model-written code; a run that left nothing readable is NoResult, never a pass or a fail.
enum RegressionRun: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case NoResult = 'no-result';
    case TimedOut = 'timed-out';
}
