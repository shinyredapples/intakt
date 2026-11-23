<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

use Intakt\Core\Platform\Contract\QueryMessage;

// Asks for the commit the base branch points at, because main's boot result is kept per main commit.
final readonly class MainCommit implements QueryMessage
{
    public function __construct(public ChangeSource $source)
    {
    }
}
