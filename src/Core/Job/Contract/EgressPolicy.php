<?php

declare(strict_types=1);

namespace Intakt\Core\Job\Contract;

enum EgressPolicy: string
{
    case Allowlist = 'allowlist';
    case None = 'none';
}
