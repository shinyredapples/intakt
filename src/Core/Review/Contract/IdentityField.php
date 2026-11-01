<?php

declare(strict_types=1);

namespace Intakt\Core\Review\Contract;

// Declared in the order of a recorded identity's parts, because writing and reading one both walk the cases.
enum IdentityField: string
{
    case Head = 'head';
    case Base = 'base';
    case TaskRevision = 'task-revision';
    case CheckCommand = 'check-command';
    case PanelPolicy = 'panel-policy';
}
