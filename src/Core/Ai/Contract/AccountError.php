<?php

declare(strict_types=1);

namespace Intakt\Core\Ai\Contract;

// Only the owner ends one of these (a new sign-in, another plan or model), so each is reported to the owner rather than waited out.
enum AccountError: string
{
    case LoginLost = 'login_lost';
    case ModelNotOnPlan = 'model_not_on_plan';
}
