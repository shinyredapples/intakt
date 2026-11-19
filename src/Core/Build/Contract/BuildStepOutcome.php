<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

enum BuildStepOutcome: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    // Neither passed nor failed, because a build job that ends with a question waits for a decision instead of handing back a change.
    case Asked = 'asked';
}
