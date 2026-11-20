<?php

declare(strict_types=1);

namespace Intakt\Plugin\Forge\Contract;

// Backed by the text the push records, because the watcher reads it back from the ledger: docs/architecture/handed-to-intakt.md
enum Opening: string
{
    case Created = 'created';
    case Updated = 'updated';
}
