<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

enum BuildStepOutcome: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    // The build job ended with a question for the maintainer instead of a change.
    case Asked = 'asked';
}
