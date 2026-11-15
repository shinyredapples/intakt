<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Throwable;

// Lets another module tell a round the Review module never stored from a failure of the query, without reaching into a use case.
interface UnknownRound extends Throwable
{
}
