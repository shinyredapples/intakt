<?php

declare(strict_types=1);

namespace Intakt\Core\Budget\Contract;

// Carries nothing, because a listener reads the notices again through OwnerNotices; dispatched only after the change is committed, so that read sees it.
final readonly class OwnerNoticesChanged
{
}
