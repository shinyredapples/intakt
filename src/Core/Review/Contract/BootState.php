<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Failed is the commit's own (its harness reported it), crashed is the boot step's (no status could be read), so a broken tool never reads as a broken head.
enum BootState: string
{
    case Booted = 'booted';
    case Failed = 'failed';
    case Crashed = 'crashed';
}
