<?php

declare(strict_types=1);

namespace Intakt\Core\Build\Contract;

enum BuildStepOutcome: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Asked = 'asked';
}
