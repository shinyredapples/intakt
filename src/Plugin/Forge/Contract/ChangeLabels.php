<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

use Intakt\Core\Review\Contract\Labels;

// Apart from Forge, because only a forge with labels has any, and a forge that cannot say gives none: an unread label never steers.
interface ChangeLabels
{
    // Because only a label from an account with write access may steer, and each family keeps the value applied last.
    public function steeringOf(string $project, OpenChange $open): Labels;
}
