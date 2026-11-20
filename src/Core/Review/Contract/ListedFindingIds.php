<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// The plain value of finding ids, which callers build; the base class is abstract so the stored serializer can carry a legacy row's keys in its own subclass.
final readonly class ListedFindingIds extends FindingIds
{
}
