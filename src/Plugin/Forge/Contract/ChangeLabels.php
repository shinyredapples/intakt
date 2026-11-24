<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Intakt\Core\Review\Contract\Labels;

// Apart from Forge, because only a forge with labels has any, and a forge that cannot say gives none: an unread label never steers.
interface ChangeLabels
{
    // Only the steering labels an account with write access applied and that are still on the change, one per family, the last applied.
    public function steeringOf(string $project, OpenChange $open): Labels;
}
