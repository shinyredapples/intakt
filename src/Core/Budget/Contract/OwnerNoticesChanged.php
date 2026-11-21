<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

// Dispatched once a notice is stored or ended, so a view of the notices can read them again; it carries nothing, because a reader reads the whole list anyway.
#[Exclude]
final readonly class OwnerNoticesChanged
{
}
