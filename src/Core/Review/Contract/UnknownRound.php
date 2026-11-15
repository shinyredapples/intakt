<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Throwable;

// An interface in the contract, because another module must tell a round the Review module never stored from any other failure of a query, and may not reach into a use case for the exception.
interface UnknownRound extends Throwable
{
}
